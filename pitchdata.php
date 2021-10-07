<?php

require 'sql.php';

// TODO: css: make everything use more color
//       banner text curved cinemascope style via svg?
//       for favorites, add column for average rating
//       "use as my default signature" checkbox on pitch form
//       maybe a link to see other pitches by the same author?  only if signature used?
//       mitigate challenge request flood failures -- last_shown per session? soft exclusion of recent?
//       add ban flag to session, and check it
//       control the bad effects of back button and refresh:
//           postbacks must not change the challenge words... use seeded random?
//           likewise use seeded random on review page?
//           don't multiply increment spam flags -- I guess check moderations for dupes
//       admin pages for checking bad words and spam:
//           starting page: view list of unanswered moderation requests
//           accept (deleting submission) or reject (clearing flag) each request
//           detail page: view sessions' stats and review history
//           ban sessions with optional bulk delete of their submissions
//           view history of accept, reject, ban, and bulk delete by other admins?
//
// BUGS: 

// If it were just the game, the database would be very simple.  But because we have to also support
// moderation and a feature for flagging garbage inputs, we have to track a bunch more detail.
// The moderation features are probably more complex than the gameplay.  See pitchgame.sql for design.

class Challenge
{
	public $subject;
	public $verb;
	public $object;
	public $subjectId;
	public $verbId;
	public $objectId;
}

class Pitch
{
	public $pitchId;
	public $subjectId;
	public $verbId;
	public $objectId;
	public $subjectNoun;
	public $verb;
	public $objectNoun;
	public $title;
	public $pitch;
	public $signature;
	public $yourRating;
}


class Connection
{
	private $marie;					// our mysqli connection
	private $preparedOK = false;
	private $sessionId;
	private $log = '';				// set to null to disable logging, '' to enable

	// EXTERNALS:

	public $lastError = '';

	public function __construct()
	{
		mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);		// throw exceptions rather than just setting marie->error
		try
		{
			$this->marie = new mysqli(null, 'pitchgame', null, 'pitchgame');
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

	public function isReady()
	{
		return $this->preparedOK;
	}

	public function getLog()
	{
		return $log;
	}

	public function getSessionByToken($token)
	{
		try
		{
			$getSession = new ScalarSelector($this->marie, $this->log, 's', 'SELECT session_id FROM sessions WHERE cookie_token = ?');
			$this->sessionId = $getSession->select($token);
			return !!$this->sessionId;
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
		}
	}

	public function makeSession($ipAddress, $userAgent)
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

	public function addWords($initialSubject, $initialVerb, $initialObject)
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

	public function getChallenge()
	{
		return $this->getChallengeImpl(true, true, true, true);
	}

	private function getChallengeImpl($bumpSubject, $bumpVerb, $bumpObject, $commit)
	{
		try
		{
			$getChallenge  = new Selector($this->marie, $this->log, '', '
			    WITH sub AS ( SELECT subject_id, word FROM subjects
			                   WHERE is_deleted = false AND last_shown < date_sub(now(), INTERVAL 1 HOUR)
			                   ORDER BY shown_ct + 2 * moderation_flag_ct, last_shown
			                   LIMIT 100 ),
			         vrb AS ( SELECT verb_id, word FROM verbs
			                   WHERE is_deleted = false AND last_shown < date_sub(now(), INTERVAL 1 HOUR)
			                   ORDER BY shown_ct + 2 * moderation_flag_ct, last_shown
			                   LIMIT 100 ),
			         obj AS ( SELECT object_id, word FROM objects
			                   WHERE is_deleted = false AND last_shown < date_sub(now(), INTERVAL 1 HOUR)
			                   ORDER BY shown_ct + 2 * moderation_flag_ct, last_shown
			                   LIMIT 100 ),
			         ras AS ( SELECT subject_id, word FROM sub ORDER BY rand() LIMIT 1 ),
			         rav AS ( SELECT verb_id, word FROM vrb ORDER BY rand() LIMIT 1 ),
			         rao AS ( SELECT object_id, word FROM obj ORDER BY rand() LIMIT 1 )
			    SELECT subject_id, ras.word AS subject_noun, verb_id, rav.word AS verb, object_id, rao.word AS object_noun
			      FROM ras, rav, rao');
			$updateSubject = new Updater($this->marie, $this->log, 'i',
			    'UPDATE subjects SET last_shown = now(), shown_ct = shown_ct + 1 WHERE subject_id = ?');
			$updateVerb    = new Updater($this->marie, $this->log, 'i',
			    'UPDATE verbs    SET last_shown = now(), shown_ct = shown_ct + 1 WHERE verb_id    = ?');
			$updateObject  = new Updater($this->marie, $this->log, 'i',
			    'UPDATE objects  SET last_shown = now(), shown_ct = shown_ct + 1 WHERE object_id  = ?');

			$challenge = new Challenge();
			if ($commit)
				$this->marie->begin_transaction();
			if ($getChallenge->select() &&
			    $getChallenge->getRow($challenge->subjectId, $challenge->subject,
			                          $challenge->verbId, $challenge->verb,
			                          $challenge->objectId, $challenge->object))
			{
				if ($bumpSubject)
					$updateSubject->update($challenge->subjectId);
				if ($bumpVerb)
					$updateVerb->update($challenge->verbId);
				if ($bumpObject)
					$updateObject->update($challenge->objectId);
				if ($commit)
					$this->marie->commit();
				return $challenge;
			}
			else
				throw new Exception('Could not retrieve challenge -- request flood?');
		}
		catch (Throwable $ex)
		{
			$this->marie->rollback();
			$this->saveError($ex);
		}
	}
	
	public function flagWordsForModeration($challenge, $badsubject, $badverb, $badobject)
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
			    'INSERT INTO moderations (session_id, subject_id, verb_id, object_id) VALUES (?, ?, ?, ?)');

			$this->marie->begin_transaction();
			$tc = $this->getChallengeImpl($badsubject, $badverb, $badobject, false);
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

	public function addPitch(&$challenge, $title, $pitch, $signature)
	{
		try
		{
			$addPitch = new Inserter($this->marie, $this->log, 'iiiisss',
			    'INSERT IGNORE INTO pitches (session_id, subject_id, verb_id, object_id, title, pitch, signature) VALUES (?, ?, ?, ?, ?, ?, ?)');
			$addPitch->insert($this->sessionId, $challenge->subjectId, $challenge->verbId,
			                  $challenge->objectId, $title, $pitch, $signature);
			return true;		// allow dupe inserts to fail silently
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
		}
	}

	public function getPitchesToReview()
	{
		try
		{
			$getPitches = new Selector($this->marie, $this->log, 'ii', '
			    WITH pits AS ( SELECT pitch_id, subject_id, verb_id, object_id, title, pitch, signature FROM pitches p
			                    WHERE is_deleted = false
			                      AND last_shown < date_sub(now(), INTERVAL 1 HOUR)
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
			     ORDER BY rand() LIMIT 4');   // limit can’t be parameterized, apparently
			$updatePitch = new Updater($this->marie, $this->log, 'i',
			    'UPDATE pitches SET last_shown = now(), shown_ct = shown_ct + 1 WHERE pitch_id  = ?');

			$this->marie->begin_transaction();
			$getPitches->select($this->sessionId, $this->sessionId);
			$result = [];
			do
			{
				$pitch = new Pitch();
				$gotten = $getPitches->getRow($pitch->pitchId, $pitch->subjectId, $pitch->verbId, $pitch->objectId,
				                              $pitch->subjectNoun, $pitch->verb, $pitch->objectNoun,
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

	public function ratePitch($pitchId, $rating)
	{
		try
		{
			$addRating = new Inserter($this->marie, $this->log, 'iii',
			    'INSERT IGNORE INTO ratings (pitch_id, session_id, rating) VALUES (?, ?, ?)');
			//$removeRating = new Updater($this->marie, $this->log, 'ii',
			//    'DELETE FROM ratings WHERE pitch_id = ? and session_id = ?');
			$markPitch = new Updater($this->marie, $this->log, 'i',
			    'UPDATE pitches SET moderation_flag_ct = moderation_flag_ct + 1 WHERE pitch_id = ?');
			$addModeration = new Inserter($this->marie, $this->log, 'ii',
			    'INSERT INTO moderations (session_id, pitch_id) VALUES (?, ?)');

			if ((int) $rating < -1 || (int) $rating > 4)
				throw new Exception("Rating '$rating' for pitch $pitchId out of range");
			//if (!(int) $rating)
			//	$removeRating->update($pitchId, $this->sessionId);
			//else
			$r = $addRating->insert($pitchId, $this->sessionId, $rating);
			if ($r && $rating < 0)
				return $markPitch->update($pitchId) && $addModeration->insert($this->sessionId, $pitchId);
			return true;
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
		}
	}

	public function getOldFavoritePitches()
	{
		try
		{
			$getPitches = new Selector($this->marie, $this->log, 'ii', '
			    WITH pits AS ( SELECT pitch_id, subject_id, verb_id, object_id, title, pitch, signature, rating
		                         FROM pitches p JOIN ratings r USING (pitch_id)
			                    WHERE is_deleted = false
			                      AND last_shown < date_sub(now(), INTERVAL 1 HOUR)
			                      AND p.session_id <> ?
			                      AND r.session_id = ?
			                      AND rating >= 3
			                    ORDER BY shown_ct + 2 * moderation_flag_ct, last_shown
			                    LIMIT 100 )
			    SELECT pitch_id, subject_id, verb_id, object_id,
			           s.word as subject_noun, v.word as verb, o.word as object_noun,
			           title, pitch, signature, rating
			      FROM pits JOIN subjects s USING (subject_id)
			                JOIN verbs v USING (verb_id)
			                JOIN objects o USING (object_id)
			     ORDER BY rand() LIMIT 3');

			$getPitches->select($this->sessionId, $this->sessionId);
			$result = [];
			do
			{
				$pitch = new Pitch();
				$gotten = $getPitches->getRow($pitch->pitchId, $pitch->subjectId, $pitch->verbId, $pitch->objectId,
				                              $pitch->subjectNoun, $pitch->verb, $pitch->objectNoun,
				                              $pitch->title, $pitch->pitch, $pitch->signature, $pitch->yourRating);
				if ($gotten)
					$result[] = $pitch;
			} while ($gotten);
			return $result;
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
		}
	}

	public function saveError($ex)
	{
		if ($ex)
			error_log(formatThrowable($ex), true);
		if (!$this->lastError && $ex)
			$this->lastError = formatThrowable($ex) . "\n\n" . $this->log;
	}
}


function formatThrowable($ex, $includeTrace = false)
{
	if (!($ex instanceof throwable))
		return null;
	$code = $ex->getCode() ? ' (' . $ex->getCode() . ')' : '';
	$line = (count($ex->getTrace()) ? $ex->getTrace()[0]['line'] . '/' : '') . $ex->getLine();
	$trace = $includeTrace ? "\n" . $ex->getTraceAsString() : '';
	$inner = $ex->getPrevious() ? "\n---- Inner exception:\n" . formatThrowable($ex->getPrevious()) : '';
	return get_class($ex) . $code . ' at line ' . $line . ': ' . $ex->getMessage()
	       . $trace . $inner;
}
?>
