<?php
// This contains database methods used for gameplay but not those used for moderation.

require 'sql.php';
require 'pitch-configure.php';


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

class TeamMemberStatus
{
	public ?int    $sessionId;
	public ?string $nickname;
	public ?int    $suggestionId;
	public ?int    $pitchId;
	public ?int    $secondsAgo;
}

class PitchGameConnection
{
	protected mysqli    $marie;
	protected bool      $preparedOK = false;
	protected int       $sessionId = 0;
	protected ?int      $teamId = null;
	protected ?int      $currentParticipationId = null;
	protected ?int      $currentRound = null;
	protected bool      $privatePlay = false;
	protected SqlLogger $log;

	// EXTERNALS:

	public ?string      $nickname = null;
	public ?string      $defaultSignature = null;
	public bool         $isBlocked = false;
	public bool         $isTester = false;
	public bool         $hasDebugAccess = false;
	public bool         $passedCaptcha = false;
	public bool         $needsCaptcha = false;
	public string       $lastError = '';

	public function __construct()
	{
		mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);		// throw exceptions rather than just setting marie->error
		try
		{
			$this->log = new SqlLogger(true);
			$this->marie = new mysqli(DB_HOST, 'pitchgame', DB_PASSWORD, 'pitchgame');
			$this->marie->set_charset('utf8mb4');
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

	public function isReady(): bool
	{
		return $this->preparedOK;
	}

	public function teamMode(): bool
	{
		return !!$this->teamId;
	}

	public function getLog(): string
	{
		return $this->log->log;
	}

	public function getSessionByToken(string $token, string $ipAddress, string $userAgent): bool
	{
		try
		{
			$minutesOld = 0;
			$blockedBy = null;
			$getSession = new Selector($this->marie, $this->log, 's',
			    'SELECT session_id, nickname, signature, blocked_by, passed_captcha, is_test, has_debug_access,
			            timestampdiff(MINUTE, when_last_used, now()) AS minutes_old
			       FROM sessions WHERE cookie_token = ?');
			$updateSession = new Updater($this->marie, $this->log, 'ssi',
			    'UPDATE sessions SET ip_address = ?, useragent = ?, when_last_used = now() WHERE session_id = ?');
			$getSession->select($token);
			$getSession->getRow($this->sessionId, $this->nickname, $this->defaultSignature, $blockedBy,
			                    $this->passedCaptcha, $this->isTester, $this->hasDebugAccess, $minutesOld);
			$this->isBlocked = !!$blockedBy;
			$this->needsCaptcha = !$this->passedCaptcha && SECURITY_LEVEL == 2;
			if ($minutesOld >= 5)
				$updateSession->update($ipAddress, $userAgent, $this->sessionId);
			return !!$this->sessionId;
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
			return false;
		}
	}

	public function makeSession(string $ipAddress, string $userAgent): ?string
	{
		try
		{
			$addSession = new Inserter($this->marie, $this->log, 'ss',
			    'INSERT INTO sessions (ip_address, useragent, cookie_token) VALUES (?, ?, uuid())');
			$getToken = new ScalarSelector($this->marie, $this->log, 'i',
			    'SELECT cookie_token FROM sessions WHERE session_id = ?');
			$this->sessionId = $addSession->insert($ipAddress, $userAgent);
			$this->needsCaptcha = SECURITY_LEVEL == 1 || SECURITY_LEVEL == 2;
			return $getToken->select($this->sessionId);
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
			return null;
		}
	}

	public function captchaPass()
	{
		try
		{
			$this->passedCaptcha = true;
			$this->needsCaptcha = false;
			$setCaptcha = new Updater($this->marie, $this->log, 'i',
			    'UPDATE sessions SET passed_captcha = true WHERE session_id = ?');
			$setCaptcha->update($this->sessionId);
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
		}
	}

	public function captchaFail($response, $token, $code)
	{
		$msg = "Session $con->sessionId flunked captcha with token $token — server responded $code:\n" . SqlLogger::nust($response) . "\n";
		error_log($msg);
		$this->log->log($msg);
	}

	public function setNickname(string $nick): bool
	{
		try
		{
			$nick = trim($nick);
			if (!$nick)
				return false;
			$this->nickname = $nick;
			$setNickname = new Updater($this->marie, $this->log, 'si',
			    'UPDATE sessions SET nickname = ? WHERE session_id = ?');
			return $setNickname->update($nick, $this->sessionId);
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
			return false;
		}
	}

	public function createTeam($private): ?string
	{
		try
		{
			$addTeam = new Inserter($this->marie, $this->log, 'i',
			    'INSERT INTO teams (is_private, token) VALUES (?, uuid())');
			$getToken = new ScalarSelector($this->marie, $this->log, 'i',
			    'SELECT token FROM teams WHERE team_id = ?');
			$teamId = $addSession->insert($private);
			return $getToken->select($teamId);
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
			return null;
		}
	}

	public function joinTeam(string $token): bool
	{
		try
		{
			$getTeam = new Selector($this->marie, $this->log, 's',
			    'SELECT team_id, is_private FROM teams WHERE token = ?');
			// XXX *** INSERT INTO participations ... UPDATE in some cases?... set $currentParticipationId and $currentRound
			// XXX     How do we tell when to start a new round??  This will require careful setup.
			$getTeam->select($token);
			$getTeam->getRow($this->teamId, $this->privatePlay);
			$this->needsCaptcha = !$this->passedCaptcha && SECURITY_LEVEL == 2;
			return !!$this->teamId;
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
			return false;
		}
	}

	public function addWords(string $initialSubject, string $initialVerb, string $initialObject): bool
	{
		try
		{
			// The unique keys on the word tables make these inserts affect zero rows and return falsy when a
			// non-team player suggests a word that has been used before in non-team play.  (Make case insensitive?)
			$addSubject    = new Inserter($this->marie, $this->log, 'sii',
			    'INSERT IGNORE INTO subjects (word, is_private, participation_id) VALUES (?, ?, ?)');
			$addVerb       = new Inserter($this->marie, $this->log, 'sii',
			    'INSERT IGNORE INTO verbs    (word, is_private, participation_id) VALUES (?, ?, ?)');
			$addObject     = new Inserter($this->marie, $this->log, 'sii',
			    'INSERT IGNORE INTO objects  (word, is_private, participation_id) VALUES (?, ?, ?)');
			$getSubject    = new ScalarSelector($this->marie, $this->log, 's', 'SELECT subject_id FROM subjects WHERE word = ?');
			$getVerb       = new ScalarSelector($this->marie, $this->log, 's', 'SELECT verb_id    FROM verbs    WHERE word = ?');
			$getObject     = new ScalarSelector($this->marie, $this->log, 's', 'SELECT object_id  FROM objects  WHERE word = ?');
			$addSuggestion = new Inserter($this->marie, $this->log, 'iiii',
			    'INSERT IGNORE INTO suggestions (session_id, subject_id, verb_id, object_id) VALUES (?, ?, ?, ?)');

			$this->marie->begin_transaction();
			$subjectId = $addSubject->insert($initialSubject, $this->privatePlay, $this->currentParticipationId)
			             ?: $getSubject->select($initialSubject);
			if (!$subjectId)
				throw new Exception("No subjectId found after insert of $initialSubject");
			$verbId    = $addVerb->insert($initialVerb, $this->privatePlay, $this->currentParticipationId)
			             ?: $getVerb->select($initialVerb);
			if (!$verbId)
				throw new Exception("No verbId found after insert of $initialVerb");
			$objectId  = $addObject->insert($initialObject, $this->privatePlay, $this->currentParticipationId)
			             ?: $getObject->select($initialObject);
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
			return false;
		}
	}

	// The purpose of the seed is to prevent users from changing their challenge with the refresh button.
	public function getChallenge(int $seed): ?Challenge
	{
		try
		{
			$getChallenge /*non-team*/ = new Selector($this->marie, $this->log, 'iii', '
			    WITH sub AS ( SELECT subject_id, word FROM subjects
			                   WHERE is_deleted = false AND is_private = false
			                   ORDER BY shown_ct + 4 * moderation_flag_ct, last_shown
			                   LIMIT 100 ),
			         vrb AS ( SELECT verb_id, word FROM verbs
			                   WHERE is_deleted = false AND is_private = false
			                   ORDER BY shown_ct + 4 * moderation_flag_ct, last_shown
			                   LIMIT 100 ),
			         obj AS ( SELECT object_id, word FROM objects
			                   WHERE is_deleted = false AND is_private = false
			                   ORDER BY shown_ct + 4 * moderation_flag_ct, last_shown
			                   LIMIT 100 ),
			         ras AS ( SELECT subject_id, word FROM sub ORDER BY rand(?) LIMIT 1 ),
			         rav AS ( SELECT verb_id, word    FROM vrb ORDER BY rand(?) LIMIT 1 ),
			         rao AS ( SELECT object_id, word  FROM obj ORDER BY rand(?) LIMIT 1 )
			    SELECT subject_id, ras.word AS subject_noun, verb_id, rav.word AS verb, object_id, rao.word AS object_noun
			      FROM ras, rav, rao');
			// For this we use teamId and currentRound as seed because it needs to match for all current participants.
			// The idea is to have a common list of all current participants which is rotated randomly but consistently.
			$getChallengeFromTeam = new Selector($this->marie, $this->log, 'iiii', '
			    ');
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
			return null;
		}
	}
	
	// both players and admins can mark words or pitches to be moderated
	public function flagWordsForModeration(Challenge $challenge, bool $badsubject, bool $badverb, bool $badobject): ?Challenge
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
			return null;
		}
	}

	public function addPitch(Challenge &$challenge, string $title, string $pitch, ?string $signature, bool $setDefaultSig): bool
	{
		try
		{
			$addPitch = new Inserter($this->marie, $this->log, 'iiiisssii',
			    'INSERT IGNORE INTO pitches (session_id, subject_id, verb_id, object_id, title, pitch, signature, is_private, participation_id)
			     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
			$updateSubject = new Updater($this->marie, $this->log, 'i',
			    'UPDATE subjects SET last_shown = now(), shown_ct = shown_ct + 1 WHERE subject_id = ?');
			$updateVerb    = new Updater($this->marie, $this->log, 'i',
			    'UPDATE verbs    SET last_shown = now(), shown_ct = shown_ct + 1 WHERE verb_id    = ?');
			$updateObject  = new Updater($this->marie, $this->log, 'i',
			    'UPDATE objects  SET last_shown = now(), shown_ct = shown_ct + 1 WHERE object_id  = ?');

			// should we make this a transaction?  probably not necessary
			$addPitch->insert($this->sessionId, $challenge->subjectId, $challenge->verbId, $challenge->objectId,
			                  $title, $pitch, $signature, $this->privatePlay, $this->currentParticipationId);
			// argh I don't remember why I postponed setting last_shown until now...
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
			return false;
		}
	}

	public function getPitchesToReview(int $seed): ?array
	{
		try
		{
			// XXX sometimes nonperformant, intermittently:
			$getPitches /*non-team*/ = new Selector($this->marie, $this->log, 'iii', '
			    WITH pits AS ( SELECT pitch_id, subject_id, verb_id, object_id, title, pitch, signature FROM pitches p
			                    WHERE is_deleted = false AND is_private = false AND session_id <> ?
			                      AND NOT EXISTS ( SELECT rating_id FROM ratings r
			                                        WHERE r.pitch_id = p.pitch_id AND r.session_id = ? )
			                    ORDER BY shown_ct + 2 * moderation_flag_ct, last_shown
			                    LIMIT 100 )
			    SELECT pitch_id, subject_id, verb_id, object_id,
			           s.word as subject_noun, v.word as verb, o.word as object_noun,
			           title, pitch, signature
			      FROM pits INNER JOIN subjects s USING (subject_id)
			                INNER JOIN verbs v USING (verb_id)
			                INNER JOIN objects o USING (object_id)
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
			return null;
		}
	}

	// Because ratePitch can get called in a loop, we persist these statements outside it.
	private ?Inserter $addRating = null;
	//private ?Updater  $removeRating;
	private ?Updater  $markPitch;
	private ?Inserter $addModeration;

	// admins use this only with rating -1, which means mark for moderation
	public function ratePitch(int $pitchId, int $rating): bool
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
			return false;
		}
	}

	public function getOldFavoritePitches($seed): ?array
	{
		try
		{
			// XXX this can be nonperformant, intermittently
			$getPitches = new Selector($this->marie, $this->log, 'iii', '
			    WITH pits AS ( SELECT pitch_id, subject_id, verb_id, object_id, title, pitch, signature, rating
		                         FROM pitches p JOIN ratings r USING (pitch_id)
			                    WHERE is_deleted = false AND is_private = false
			                      AND p.session_id <> ? AND r.session_id = ?
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
			      FROM pits INNER JOIN subjects s USING (subject_id)
			                INNER JOIN verbs v USING (verb_id)
			                INNER JOIN objects o USING (object_id)
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
			return null;
		}
	}

	public function saveError(?Throwable $ex)
	{
		if ($ex)
			error_log(SqlLogger::formatThrowable($ex));
		if (!$this->lastError && $ex)
			$this->lastError = SqlLogger::formatThrowable($ex) . "\n\n" . $this->log->log;
	}
}
?>