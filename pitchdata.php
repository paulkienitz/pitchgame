<?php
// This contains database methods used for gameplay but not those used for moderation.

require 'sql.php';


// objects returned by PitchGameConnection methods:

// Our terminology is that a "suggestion" is the three words input by a player, whereas
// a "challenge" is a scrambled three word idea output by the game to prompt a pitch.
// This structure can contain either one.  We sometimes use the word "idea" for either.
class Challenge
{
	public ?string $subject;
	public ?string $verb;
	public ?string $object;
	public ?int    $subjectId;
	public ?int    $verbId;
	public ?int    $objectId;
}

// A pitch includes the three word challenge which was used to prompt it.
class Pitch extends Challenge
{
	public ?int    $pitchId;
	public ?string $title;
	public ?string $pitch;
	public ?string $signature;
}

class RatedPitch extends Pitch
{
	public ?int    $yourRating;
	public ?float  $averageRating;
	public ?int    $ratingCount;
}


class PitchGameConnection
{
	protected mysqli    $marie;
	protected bool      $preparedOK = false;
	protected int       $sessionId = 0;
	protected SqlLogger $log;

	// EXTERNALS:

	public ?string    $defaultSignature;
	public bool       $isBlocked = false;
	public bool       $isTester = false;
	public bool       $hasDebugAccess = false;
	public string     $lastError = '';

	public function __construct()
	{
		mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);		// throw exceptions rather than just setting marie->error
		try
		{
			$this->marie = new mysqli(null, 'pitchgame', null, 'pitchgame');
			$this->marie->set_charset('utf8mb4');
			$this->log = new SqlLogger(true);
			$this->preparedOK = true;		// none of the preparing has actually been done... this is no longer very meaningful
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
		}
	}
	
	public function __destruct()
	{
		$this->marie->close();
	}

	public function isReady()
	{
		return $this->preparedOK;
	}

	public function getLog()
	{
		return $this->log->log;
	}

	public function getSessionByToken(string $token, string $ipAddress)
	{
		try
		{
			$minutesOld = 0;
			$blockedBy = null;
			$getSession = new Selector($this->marie, $this->log, 's',
			    'SELECT session_id, signature, blocked_by, is_test, has_debug_access,
			            timestampdiff(MINUTE, when_last_used, now()) AS minutes_old
			       FROM sessions WHERE cookie_token = ?');
			$updateSession = new Updater($this->marie, $this->log, 'si',
			    'UPDATE sessions SET ip_address = ?, when_last_used = now() WHERE session_id = ?');
			$getSession->select($token);
			$getSession->getRow($this->sessionId, $this->defaultSignature, $blockedBy, $this->isTester, $this->hasDebugAccess, $minutesOld);
			$this->isBlocked = !!$blockedBy;
			if ($minutesOld >= 5)
				$updateSession->update($ipAddress, $this->sessionId);
			return !!$this->sessionId;
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
		}
	}

	public function makeSession(string $ipAddress, string $userAgent)
	{
		try
		{
			$addSession = new Inserter($this->marie, $this->log, 'ss',
			    'INSERT INTO sessions (ip_address, useragent, cookie_token) VALUES (?, ?, uuid())');
			$getToken = new ScalarSelector($this->marie, $this->log, 'i',
			    'SELECT cookie_token FROM sessions WHERE session_id = ?');
			$this->sessionId = $addSession->insert($ipAddress, $userAgent);
			return $getToken->select($this->sessionId);
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
		}
	}

	// How exactly should we handle sessions in team play?  Does the same cookie apply to team and non-team sessions?

	public function addWords(string $initialSubject, string $initialVerb, string $initialObject)
	{
		try
		{
			$addSubject    = new Inserter($this->marie, $this->log, 's', 'INSERT IGNORE INTO subjects (word) VALUES (?)');
			$addVerb       = new Inserter($this->marie, $this->log, 's', 'INSERT IGNORE INTO verbs    (word) VALUES (?)');
			$addObject     = new Inserter($this->marie, $this->log, 's', 'INSERT IGNORE INTO objects  (word) VALUES (?)');
			$getSubject    = new ScalarSelector($this->marie, $this->log, 's', 'SELECT subject_id FROM subjects WHERE word = ?');
			$getVerb       = new ScalarSelector($this->marie, $this->log, 's', 'SELECT verb_id    FROM verbs    WHERE word = ?');
			$getObject     = new ScalarSelector($this->marie, $this->log, 's', 'SELECT object_id  FROM objects  WHERE word = ?');
			$addSuggestion = new Inserter($this->marie, $this->log, 'iiii',
			    'INSERT IGNORE INTO suggestions (session_id, subject_id, verb_id, object_id) VALUES (?, ?, ?, ?)');

			$this->marie->begin_transaction();
			$subjectId = $addSubject->insert($initialSubject) ?: $getSubject->select($initialSubject);
			if (!$subjectId)
				throw new Exception("No subjectId found after insert of $initialSubject");
			$verbId    = $addVerb->insert($initialVerb)       ?: $getVerb->select($initialVerb);
			if (!$verbId)
				throw new Exception("No verbId found after insert of $initialVerb");
			$objectId  = $addObject->insert($initialObject)   ?: $getObject->select($initialObject);
			if (!$objectId)
				throw new Exception("No objectId found after insert of $initialObject");
			$addSuggestion->insert($this->sessionId, $subjectId, $verbId, $objectId);
			$this->marie->commit();
			return true;		// addSuggestion dupes fail silently
		}
		catch (Throwable $ex)
		{
			$this->marie->rollback();
			$this->saveError($ex);
		}
	}

	// The purpose of the seed is to prevent users from changing their challenge with the refresh button.
	public function getChallenge(int $seed)
	{
		try
		{
			$getChallenge  = new Selector($this->marie, $this->log, 'iii', '
			    WITH sub AS ( SELECT subject_id, word FROM subjects
			                   WHERE is_deleted = false  -- AND last_shown < date_sub(now(), INTERVAL 1 HOUR)
			                   ORDER BY shown_ct + 4 * moderation_flag_ct, last_shown
			                   LIMIT 100 ),
			         vrb AS ( SELECT verb_id, word FROM verbs
			                   WHERE is_deleted = false  -- AND last_shown < date_sub(now(), INTERVAL 1 HOUR)
			                   ORDER BY shown_ct + 4 * moderation_flag_ct, last_shown
			                   LIMIT 100 ),
			         obj AS ( SELECT object_id, word FROM objects
			                   WHERE is_deleted = false  -- AND last_shown < date_sub(now(), INTERVAL 1 HOUR)
			                   ORDER BY shown_ct + 4 * moderation_flag_ct, last_shown
			                   LIMIT 100 ),
			         ras AS ( SELECT subject_id, word FROM sub ORDER BY rand(?) LIMIT 1 ),
			         rav AS ( SELECT verb_id, word    FROM vrb ORDER BY rand(?) LIMIT 1 ),
			         rao AS ( SELECT object_id, word  FROM obj ORDER BY rand(?) LIMIT 1 )
			    SELECT subject_id, ras.word AS subject_noun, verb_id, rav.word AS verb, object_id, rao.word AS object_noun
			      FROM ras, rav, rao');
			// todo: $getChallengeFromTeam version of query

			$challenge = new Challenge();
			if ($getChallenge->select($seed, $seed, $seed) &&
			    $getChallenge->getRow($challenge->subjectId, $challenge->subject,
			                          $challenge->verbId, $challenge->verb,
			                          $challenge->objectId, $challenge->object))
				return $challenge;
			else
				throw new Exception('Could not retrieve challenge -- request flood?');
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
		}
	}
	
	// both players and admins can mark words or pitches to be moderated
	public function flagWordsForModeration(Challenge $challenge, bool $badsubject, bool $badverb, bool $badobject)
	{
	    try
		{
			$markSubject = new Updater($this->marie, $this->log, 'i',
			    'UPDATE subjects SET moderation_flag_ct = moderation_flag_ct + 1 WHERE subject_id = ?');
			$markVerb    = new Updater($this->marie, $this->log, 'i',
			    'UPDATE verbs    SET moderation_flag_ct = moderation_flag_ct + 1 WHERE verb_id    = ?');
			$markObject  = new Updater($this->marie, $this->log, 'i',
			    'UPDATE objects  SET moderation_flag_ct = moderation_flag_ct + 1 WHERE object_id  = ?');
			$addModeration = new Inserter($this->marie, $this->log, 'iiii',
			    'INSERT IGNORE INTO moderations (session_id, subject_id, verb_id, object_id) VALUES (?, ?, ?, ?)');

			$this->marie->begin_transaction();
			$tc = $this->getChallenge(rand());
			if ($badsubject || $badverb || $badobject)
				$addModeration->insert($this->sessionId, $badsubject ? $challenge->subjectId : null,
				                       $badverb ? $challenge->verbId : null, $badobject ? $challenge->objectId : null);
			if ($badsubject)
			{
				$markSubject->update($challenge->subjectId);
				$challenge->subjectId = $tc->subjectId;
				$challenge->subject = $tc->subject;
			}
			if ($badverb)
			{
				$markVerb->update($challenge->verbId);
				$challenge->verbId = $tc->verbId;
				$challenge->verb = $tc->verb;
			}
			if ($badobject)
			{
				$markObject->update($challenge->objectId);
				$challenge->objectId = $tc->objectId;
				$challenge->object = $tc->object;
			}
			$this->marie->commit();
			return $challenge;
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
		}
	}

	public function addPitch(Challenge &$challenge, string $title, string $pitch, ?string $signature, bool $setDefaultSig)
	{
		try
		{
			$addPitch = new Inserter($this->marie, $this->log, 'iiiisss',
			    'INSERT IGNORE INTO pitches (session_id, subject_id, verb_id, object_id, title, pitch, signature) VALUES (?, ?, ?, ?, ?, ?, ?)');
			$updateSubject = new Updater($this->marie, $this->log, 'i',
			    'UPDATE subjects SET last_shown = now(), shown_ct = shown_ct + 1 WHERE subject_id = ?');
			$updateVerb    = new Updater($this->marie, $this->log, 'i',
			    'UPDATE verbs    SET last_shown = now(), shown_ct = shown_ct + 1 WHERE verb_id    = ?');
			$updateObject  = new Updater($this->marie, $this->log, 'i',
			    'UPDATE objects  SET last_shown = now(), shown_ct = shown_ct + 1 WHERE object_id  = ?');

			// should we make this a transaction?  probably not necessary
			$addPitch->insert($this->sessionId, $challenge->subjectId, $challenge->verbId,
			                  $challenge->objectId, $title, $pitch, $signature);
			$updateSubject->update($challenge->subjectId);
			$updateVerb->update($challenge->verbId);
			$updateObject->update($challenge->objectId);
			if ($setDefaultSig)
			{
				$setSignature = new Updater($this->marie, $this->log, 'si',
				    'UPDATE sessions SET signature = ? WHERE session_id = ?');
				$setSignature->update($signature, $this->sessionId);
				$this->defaultSignature = $signature;
			}
			return true;		// allow dupe inserts to fail silently
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
		}
	}

	public function getPitchesToReview(int $seed)
	{
		try
		{
			// XXX sometimes nonperformant, intermittently:
			$getPitches = new Selector($this->marie, $this->log, 'iii', '
			    WITH pits AS ( SELECT pitch_id, subject_id, verb_id, object_id, title, pitch, signature FROM pitches p
			                    WHERE is_deleted = false
			                      -- AND last_shown < date_sub(now(), INTERVAL 1 HOUR)
			                      AND session_id <> ?
			                      AND NOT EXISTS ( SELECT rating_id FROM ratings r
			                                        WHERE r.pitch_id = p.pitch_id AND r.session_id = ? )
			                    ORDER BY shown_ct + 2 * moderation_flag_ct, last_shown
			                    LIMIT 100 )
			    SELECT pitch_id, subject_id, verb_id, object_id,
			           s.word as subject_noun, v.word as verb, o.word as object_noun,
			           title, pitch, signature
			      FROM pits JOIN subjects s USING (subject_id)
			                JOIN verbs v USING (verb_id)
			                JOIN objects o USING (object_id)
			     ORDER BY rand(?) LIMIT 4');   // limit can’t be parameterized, apparently
			// todo: add $getPitchesFromTeam version of query
			$updatePitch = new Updater($this->marie, $this->log, 'i',
			    'UPDATE pitches SET last_shown = now(), shown_ct = shown_ct + 1 WHERE pitch_id  = ?');

			$this->marie->begin_transaction();
			$getPitches->select($this->sessionId, $this->sessionId, $seed);
			$result = [];
			do
			{
				$pitch = new Pitch();
				$gotten = $getPitches->getRow($pitch->pitchId, $pitch->subjectId, $pitch->verbId, $pitch->objectId,
				                              $pitch->subject, $pitch->verb, $pitch->object,
				                              $pitch->title, $pitch->pitch, $pitch->signature);
				if ($gotten)
				{
					$result[] = $pitch;
					$updatePitch->update($pitch->pitchId);
				}
			} while ($gotten);
			$this->marie->commit();
			return $result;
		}
		catch (Throwable $ex)
		{
			$this->marie->rollback();
			$this->saveError($ex);
		}
	}

	// Because ratePitch can get called in a loop, we persist these statements outside it.
	private ?Inserter $addRating = null;
	//private ?Updater  $removeRating;
	private ?Updater  $markPitch;
	private ?Inserter $addModeration;

	// admins use this only with rating -1, which means mark for moderation
	public function ratePitch(int $pitchId, int $rating)
	{
		try
		{
			if ((int) $rating < -1 || (int) $rating > 4)
				throw new Exception("Rating '$rating' for pitch $pitchId out of range");
			if (!$this->addRating)
			{
				$this->addRating = new Inserter($this->marie, $this->log, 'iii',
				    'INSERT IGNORE INTO ratings (pitch_id, session_id, rating) VALUES (?, ?, ?)');
				//$this->removeRating = new Updater($this->marie, $this->log, 'ii',
				//    'DELETE FROM ratings WHERE pitch_id = ? and session_id = ?');
				$this->markPitch = new Updater($this->marie, $this->log, 'i',
				    'UPDATE pitches SET moderation_flag_ct = moderation_flag_ct + 1 WHERE pitch_id = ?');
				$this->addModeration = new Inserter($this->marie, $this->log, 'ii',
				    'INSERT IGNORE INTO moderations (session_id, pitch_id) VALUES (?, ?)');
			}
			//if (!(int) $rating)
			//	$this->removeRating->update($pitchId, $this->sessionId);
			//else
			$r = $this->addRating->insert($pitchId, $this->sessionId, $rating);
			if ($r && $rating < 0)
				return $this->markPitch->update($pitchId) &&
				       $this->addModeration->insert($this->sessionId, $pitchId);
			return true;
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
		}
	}

	public function getOldFavoritePitches($seed)
	{
		try
		{
			// XXX this can be nonperformant, intermittently
			$getPitches = new Selector($this->marie, $this->log, 'iii', '
			    WITH pits AS ( SELECT pitch_id, subject_id, verb_id, object_id, title, pitch, signature, rating
		                         FROM pitches p JOIN ratings r USING (pitch_id)
			                    WHERE is_deleted = false
			                      -- AND last_shown < date_sub(now(), INTERVAL 1 HOUR)
			                      AND p.session_id <> ?
			                      AND r.session_id = ?
			                      AND rating >= 3
			                    ORDER BY shown_ct + 2 * moderation_flag_ct, last_shown
			                    LIMIT 100 )
			    SELECT pitch_id, subject_id, verb_id, object_id,
			           s.word as subject_noun, v.word as verb, o.word as object_noun,
			           title, pitch, signature, rating,
			           ( SELECT avg(rating) FROM ratings rr
			              WHERE rr.pitch_id = pits.pitch_id ) AS avg_rating,
			           ( SELECT count(rating) FROM ratings rrr
			              WHERE rrr.pitch_id = pits.pitch_id ) AS rating_ct
			      FROM pits JOIN subjects s USING (subject_id)
			                JOIN verbs v USING (verb_id)
			                JOIN objects o USING (object_id)
			     ORDER BY rand(?) LIMIT 3');

			$getPitches->select($this->sessionId, $this->sessionId, $seed);
			$results = [];
			do
			{
				$pitch = new RatedPitch();
				$gotten = $getPitches->getRow($pitch->pitchId, $pitch->subjectId, $pitch->verbId, $pitch->objectId,
				                              $pitch->subject, $pitch->verb, $pitch->object,
				                              $pitch->title, $pitch->pitch, $pitch->signature,
				                              $pitch->yourRating, $pitch->averageRating, $pitch->ratingCount);
				if ($gotten)
					$results[] = $pitch;
			} while ($gotten);
			return $results;
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
		}
	}

	public function saveError(?Throwable $ex)
	{
		if ($ex)
			error_log(formatThrowable($ex, true));
		if (!$this->lastError && $ex)
			$this->lastError = formatThrowable($ex) . "\n\n" . $this->log->log;
	}
}


function formatThrowable(?Throwable $ex, bool $includeTrace = false)
{
	if (!$ex)
		return null;
	$code = $ex->getCode() ? ' (' . $ex->getCode() . ')' : '';
	$line = (count($ex->getTrace()) ? $ex->getTrace()[0]['line'] . '/' : '') . $ex->getLine();
	$trace = $includeTrace ? "\n" . $ex->getTraceAsString() : '';
	$inner = $ex->getPrevious() ? "\n---- Inner exception:\n" . formatThrowable($ex->getPrevious()) : '';
	return get_class($ex) . $code . ' at line ' . $line . ': ' . $ex->getMessage()
	       . $trace . $inner;
}
?>