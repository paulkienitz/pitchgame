<?php
// If it were just the game, the database would be pretty simple.  But because we have to also support
// moderation and a feature for flagging garbage inputs, we have to track a bunch more detail.
// The moderation features are probably more complex than the gameplay.  See pitchgame tables.sql for design.

require 'sql.php';


// objects returned by PitchGameConnection methods:

class Challenge
{
	public ?string $subject;
	public ?string $verb;
	public ?string $object;
	public ?int    $subjectId;
	public ?int    $verbId;
	public ?int    $objectId;
}

class Pitch extends Challenge
{
	public ?int    $pitchId;
	public ?string $title;
	public ?string $pitch;
	public ?string $signature;
	public ?int    $yourRating;
	public ?float  $averageRating;
	public ?int    $ratingCount;
}

class ModerationRequest extends Challenge
{
	public ?int    $moderationId;
	public ?string $whenRequested;
	public ?int    $requestorSessionId;
	public ?int    $flagDupes;
/*}
class ModRequestPitch extends ModerationRequest
{*/
	public ?int    $pitchId;
	public ?int    $pitchSessionId;
	public ?string $whenPitched;
	public ?int    $pitchFlagCount;
	public ?string $title;
	public ?string $pitch;
	public ?string $signature;
	// challenge words are those that inspired the pitch
/*}
public ModRequestWords extends ModerationRequest
{*/
	public ?string $whenSubject;		// when first submitted... add dupe count?
	public ?int    $subjectSessionId;	// who first submitted
	public ?int    $subjectFlagCount;
	public ?int    $subjectDupes;
	public ?string $whenVerb;
	public ?int    $verbSessionId;
	public ?int    $verbFlagCount;
	public ?int    $verbDupes;
	public ?string $whenObject;
	public ?int    $objectSessionId;
	public ?int    $objectFlagCount;
	public ?int    $objectDupes;
	// challenge words are null for parts not flagged
}

class SessionSummary
{
	public ?int    $blockedBy;
	public ?string $moderationStatus;
	public ?bool   $isTest;
	public ?string $ipAddress;
	public ?string $userAgent;
	public ?string $signature;
	public ?string $whenCreated;
	public ?int    $teamId;
	public ?int    $teamUseCount;
	public ?int    $ideasCount;
	public ?string $ideasEarliest;
	public ?string $ideasLatest;
	public ?int    $wordsShownCount;
	public ?int    $wordsFlaggedCount;
	public ?int    $wordsDeletedCount;
	public ?int    $wordsModeratedCount;  // marked as misspelled or something, but not deleted
	public ?int    $pitchesCount;
	public ?string $pitchesEarliest;
	public ?string $pitchesLatest;
	public ?int    $pitchesShownCount;
	public ?int    $pitchesFlaggedCount;
	public ?int    $pitchesDeletedCount;
	public ?int    $pitchesModeratedCount;
	public ?int    $modRequestsCount;
	public ?string $modRequestsEarliest;
	public ?string $modRequestsLatest;
	public ?int    $modRequestsAcceptedCount;
	public ?int    $modRequestsRejectedCount;
}

class HistoryEntry extends Challenge
{
	public ?string $whenPosted;
	public ?int    $pitchId;
	public ?int    $suggestionId;
	public ?string $title;
	public ?string $pitch;
	public ?string $signature;
	public ?int    $pitchShownCount;
	public ?int    $subjectShownCount;
	public ?int    $verbShownCount;
	public ?int    $objectShownCount;
	public ?int    $pitchFlagCount;
	public ?int    $subjectFlagCount;
	public ?int    $verbFlagCount;
	public ?int    $objectFlagCount;
	public ?string $pitchModStatus;
	public ?string $subjectModStatus;
	public ?string $verbModStatus;
	public ?string $objectModStatus;
	public ?bool   $pitchDeleted;
	public ?bool   $subjectDeleted;
	public ?bool   $verbDeleted;
	public ?bool   $objectDeleted;

	public function liveP()   { return !$this->pitchDeleted   && !$this->pitchModStatus   && !$this->pitchFlagCount;   }
	public function liveS()   { return !$this->subjectDeleted && !$this->subjectModStatus && !$this->subjectFlagCount; }
	public function liveV()   { return !$this->verbDeleted    && !$this->verbModStatus    && !$this->verbFlagCount;    }
	public function liveO()   { return !$this->objectDeleted  && !$this->objectModStatus  && !$this->objectFlagCount;  }
	public function deleted() { return $this->pitchId ? $this->pitchDeleted :
	                                   $this->subjectDeleted && $this->verbDeleted && $this->objectDeleted; }
}


class PitchGameConnection
{
	private mysqli    $marie;
	private bool      $preparedOK = false;
	private int       $sessionId = 0;
	private SqlLogger $log;

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

	// The purpose of the seed is to prevent users frim changing their challenge with the refresh button.
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
			// XXX sometimes nonperformant, inteittently:
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

	// Because ratePitch gets called in a loop, we persust these statements outside it.
	private ?Inserter $addRating = null;
	//private ?Updater  $removeRating;
	private ?Updater  $markPitch;
	private ?Inserter $addModeration;

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
			// XXX this can be nonperformant
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
				$pitch = new Pitch();
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

	public function getRecentModerationRequests()
	{
		try
		{
			$getRequests = new Selector($this->marie, $this->log, '', '
			    WITH mrq AS ( SELECT moderation_id, pitch_id,
			                         m.subject_id, min(s.suggestion_id) AS subj_sugg, count(s.suggestion_id) AS subj_dupe,
			                         m.verb_id,    min(v.suggestion_id) AS verb_sugg, count(v.suggestion_id) AS verb_dupe,
			                         m.object_id,  min(o.suggestion_id) AS obj_sugg,  count(o.suggestion_id) AS obj_dupe
			                    FROM moderations m LEFT JOIN
			                         suggestions s ON m.subject_id = s.subject_id LEFT JOIN
			                         suggestions v ON m.verb_id    = v.verb_id    LEFT JOIN
			                         suggestions o ON m.object_id  = o.object_id
			                   WHERE accepted_by IS NULL AND rejected_by IS NULL
			                     AND EXISTS ( SELECT 1 FROM subjects
			                                   WHERE subject_id = m.subject_id AND is_deleted = false AND moderation_status IS NULL
			                                  UNION SELECT 1 from verbs
			                                   WHERE verb_id = m.verb_id AND is_deleted = false AND moderation_status IS NULL
			                                  UNION SELECT 1 from objects
			                                   WHERE object_id = m.object_id AND is_deleted = false AND moderation_status IS NULL
			                                  UNION SELECT 1 from pitches
			                                   WHERE pitch_id = m.pitch_id AND is_deleted = false AND moderation_status IS NULL )
			                   GROUP BY moderation_id, when_submitted, pitch_id, subject_id, verb_id, object_id ),
			         muq as ( SELECT pitch_id, subject_id, verb_id, object_id,
			                         subj_sugg, verb_sugg, obj_sugg, subj_dupe, verb_dupe, obj_dupe,
			                         count(DISTINCT moderation_id) AS flag_dupe,
			                         max(moderation_id) AS last_req_id, min(moderation_id) AS first_req_id
			                    FROM mrq GROUP BY pitch_id, subject_id, verb_id, object_id,
			                                      subj_sugg, verb_sugg, obj_sugg, subj_dupe, verb_dupe, obj_dupe ),
			         ten as ( SELECT moderation_id, session_id, when_submitted,
			                         muq.pitch_id, muq.subject_id, muq.verb_id, muq.object_id,
			                         subj_sugg, verb_sugg, obj_sugg, subj_dupe, verb_dupe, obj_dupe, flag_dupe
			                    FROM muq INNER JOIN moderations ON last_req_id = moderation_id
			                   ORDER BY last_req_id - first_req_id DESC, moderation_id DESC
			                   LIMIT 10 )
			    SELECT ten.moderation_id, ten.when_submitted, ten.session_id AS requestor_session_id, ten.flag_dupe,
			           p.session_id AS pitch_session_id, ss.session_id AS subject_session_id,
			           sv.session_id AS verb_session_id, so.session_id AS object_session_id,
			           p.moderation_flag_ct AS pitch_flag_ct, s.moderation_flag_ct AS subj_flag_ct,
			           v.moderation_flag_ct AS verb_flag_ct,  o.moderation_flag_ct AS obj_flag_ct,
			           p.when_submitted AS when_pitch, ss.when_suggested AS when_subj,
			           sv.when_suggested AS when_verb, so.when_suggested AS when_obj,
			           p.pitch_id, p.title, p.pitch, p.signature, ten.subj_dupe, ten.verb_dupe, ten.obj_dupe,
			           ifnull(ps.subject_id, s.subject_id) AS subject_id, ifnull(ps.word, s.word) AS subject,
			           ifnull(pv.verb_id,    v.verb_id)    AS verb_id,    ifnull(pv.word, v.word) AS verb,
			           ifnull(po.object_id,  o.object_id)  AS object_id,  ifnull(po.word, o.word) AS object
			      FROM ten LEFT JOIN
			           pitches p   ON ten.pitch_id = p.pitch_id AND p.is_deleted = false AND p.moderation_status IS NULL LEFT JOIN
			           subjects ps ON p.subject_id = ps.subject_id LEFT JOIN
			           verbs pv    ON p.verb_id    = pv.verb_id    LEFT JOIN
			           objects po  ON p.object_id  = po.object_id  LEFT JOIN
			           subjects s ON ten.subject_id = s.subject_id AND s.is_deleted = false AND s.moderation_status IS NULL LEFT JOIN
			           verbs v    ON ten.verb_id    = v.verb_id    AND v.is_deleted = false AND v.moderation_status IS NULL LEFT JOIN
			           objects o  ON ten.object_id  = o.object_id  AND o.is_deleted = false AND o.moderation_status IS NULL LEFT JOIN
			           suggestions ss ON ten.subj_sugg = ss.suggestion_id LEFT JOIN
			           suggestions sv ON ten.verb_sugg = sv.suggestion_id LEFT JOIN
			           suggestions so ON ten.obj_sugg  = so.suggestion_id');

			$getRequests->select();
			$results = [];
			do {
				$rq = new ModerationRequest();
				$gotten = $getRequests->getRow($rq->moderationId, $rq->whenRequested, $rq->requestorSessionId, $rq->flagDupes,
				                               $rq->pitchSessionId, $rq->subjectSessionId, $rq->verbSessionId, $rq->objectSessionId,
				                               $rq->pitchFlagCount, $rq->subjectFlagCount, $rq->verbFlagCount, $rq->objectFlagCount,
				                               $rq->whenPitched, $rq->whenSubject, $rq->whenVerb, $rq->whenObject,
				                               $rq->pitchId, $rq->title, $rq->pitch, $rq->signature,
				                               $rq->subjectDupes, $rq->verbDupes, $rq->objectDupes,
				                               $rq->subjectId, $rq->subject, $rq->verbId, $rq->verb, $rq->objectId, $rq->object);
				if ($gotten)
					$results[] = $rq;
			} while ($gotten);
			return $results;
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
		}
	}

	public function getSessionHistory($sessionId)
	{
		try
		{
			$getHistory = new Selector($this->marie, $this->log, 'ii', '
			    WITH ide AS ( SELECT when_suggested AS when_posted, suggestion_id, subject_id, verb_id, object_id,
			                         s.word AS subject, v.word AS verb, o.word AS object,
			                         s.shown_ct AS s_shown_ct, v.shown_ct AS v_shown_ct, o.shown_ct AS o_shown_ct,
			                         s.moderation_flag_ct AS s_flag_ct, v.moderation_flag_ct AS v_flag_ct, o.moderation_flag_ct AS o_flag_ct,
			                         s.moderation_status AS s_mod_status, v.moderation_status AS v_mod_status, o.moderation_status AS o_mod_status,
			                         s.is_deleted AS s_is_deleted, v.is_deleted AS v_is_deleted, o.is_deleted AS o_is_deleted,
			                         NULL AS pitch_id, NULL AS title, NULL AS pitch, NULL AS signature,
			                         NULL AS p_shown_ct, NULL AS p_flag_ct, NULL AS p_mod_status, NULL AS p_is_deleted
			                    FROM suggestions JOIN
			                         subjects s USING (subject_id) JOIN
			                         verbs v    USING (verb_id) JOIN
			                         objects o  USING (object_id)
			                   WHERE session_id = ? ),
			         pit AS ( SELECT when_submitted AS when_posted, NULL AS suggestion_id, NULL AS subject_id, NULL AS verb_id, NULL AS object_id,
			                         NULL AS subject, NULL AS verb, NULL AS object, NULL AS s_shown_ct, NULL AS v_shown_ct, NULL AS i_shown_ct,
			                         NULL AS s_flag_ct, NULL AS v_flag_ct, NULL AS o_flag_ct, NULL AS s_mod_status, NULL AS v_mod_status, NULL AS o_mod_status,
			                         NULL AS s_is_deleted, NULL AS v_us_deleted, NULL AS o_is_deleted,
			                         pitch_id, title, pitch, signature,
			                         shown_ct AS p_shown_ct, moderation_flag_ct AS p_flag_ct,
			                         moderation_status AS p_mod_status, is_deleted AS p_is_deleted
			                    FROM pitches WHERE session_id = ? )
			    SELECT * FROM pit UNION SELECT * FROM ide
                 ORDER BY when_posted DESC');

			$getHistory->select($sessionId, $sessionId);
			$results = [];
			do {
				$h = new HistoryEntry();
				$gotten = $getHistory->getRow($h->whenPosted, $h->suggestionId, $h->subjectId, $h->verbId, $h->objectId,
				                              $h->subject, $h->verb, $h->object,
				                              $h->subjectShownCount, $h->verbShownCount, $h->objectShownCount,
				                              $h->subjectFlagCount, $h->verbFlagCount, $h->objectFlagCount,
				                              $h->subjectModStatus, $h->verbModStatus, $h->objectModStatus,
				                              $h->subjectDeleted, $h->verbDeleted, $h->objectDeleted,
				                              $h->pitchId, $h->title, $h->pitch, $h->signature,
				                              $h->pitchShownCount, $h->pitchFlagCount, $h->pitchModStatus, $h->pitchDeleted);
				if ($gotten)
					$results[] = $h;
			} while ($gotten);
			return $results;
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
		}
	}

	// Because saveJudgment gets called in a loop, we persist these statements outside it.
	private ?Updater $absolvePitch = null;
	private ?Updater $judgePitch;
	private ?Updater $absolveSubject;
	private ?Updater $judgeSubject;
	private ?Updater $absolveVerb;
	private ?Updater $judgeVerb;
	private ?Updater $absolveObject;
	private ?Updater $judgeObject;
	private ?Updater $acceptRequest;
	private ?Updater $rejectRequest;

	public function saveJudgment(ModerationRequest $modreq, ?string $judgmentP, ?string $judgmentS, ?string $judgmentV, ?string $judgmentO)
	{
		$code = null;
		$reject = false;
		$delete = false;
		try
		{
			// I don't think I'll transactionalize this.
			// In hindsight, I wish I'd made a common base table for subjects, verbs  objects, and pitches.  Need a way to DRY this out.
			if (!$this->absolvePitch)
			{
				$this->absolvePitch = new Updater($this->marie, $this->log, 'i',
				    'UPDATE pitches SET moderation_flag_ct = 0, moderation_status = NULL WHERE pitch_id = ?');
				$this->judgePitch = new Updater($this->marie, $this->log, 'isi',
				    'UPDATE pitches SET is_deleted = ?, moderation_status = ? WHERE pitch_id = ?');
				$this->absolveSubject = new Updater($this->marie, $this->log, 'i',
				    'UPDATE subjects SET moderation_flag_ct = 0, moderation_status = NULL WHERE subject_id = ?');
				$this->judgeSubject = new Updater($this->marie, $this->log, 'isi',
				    'UPDATE subjects SET is_deleted = ?, moderation_status = ? WHERE subject_id = ?');
				$this->absolveVerb = new Updater($this->marie, $this->log, 'i',
				    'UPDATE verbs SET moderation_flag_ct = 0, moderation_status = NULL WHERE verb_id = ?');
				$this->judgeVerb = new Updater($this->marie, $this->log, 'isi',
				    'UPDATE verbs SET is_deleted = ?, moderation_status = ? WHERE verb_id = ?');
				$this->absolveObject = new Updater($this->marie, $this->log, 'i',
				    'UPDATE objects SET moderation_flag_ct = 0, moderation_status = NULL WHERE object_id = ?');
				$this->judgeObject = new Updater($this->marie, $this->log, 'isi',
				    'UPDATE objects SET is_deleted = ?, moderation_status = ? WHERE object_id = ?');
				// acceptRequest and rejectRequest may both be used; if so, leave both fields set to indicate mixed outcome
				$this->acceptRequest = new Updater($this->marie, $this->log, 'ii',
				    'UPDATE moderations SET accepted_by = ? WHERE moderation_id = ?');
				$this->rejectRequest = new Updater($this->marie, $this->log, 'ii',
				    'UPDATE moderations SET rejected_by = ? WHERE moderation_id = ?');
			}
			if ($modreq->pitchId && $judgmentP)
			{
				$this->interpretJudgment($judgmentP, $delete, $reject, $code);
				if ($reject)
				{
					$this->absolvePitch->update($modreq->pitchId);
					$this->rejectRequest->update($this->sessionId, $modreq->moderationId);
				}
				else
				{
					$this->judgePitch->update($delete, $code, $modreq->pitchId);
					$this->acceptRequest->update($this->sessionId, $modreq->moderationId);
				}
			}
			if ($modreq->subjectId && $judgmentS)
			{
				$this->interpretJudgment($judgmentS, $delete, $reject, $code);
				if ($reject)
				{
					$this->absolveSubject->update($modreq->subjectId);
					$this->rejectRequest->update($this->sessionId, $modreq->moderationId);
				}
				else
				{
					$this->judgeSubject->update($delete, $code, $modreq->subjectId);
					$this->acceptRequest->update($this->sessionId, $modreq->moderationId);
				}
			}
			if ($modreq->verbId && $judgmentV)
			{
				$this->interpretJudgment($judgmentV, $delete, $reject, $code);
				if ($reject)
				{
					$this->absolveVerb->update($modreq->verbId);
					$this->rejectRequest->update($this->sessionId, $modreq->moderationId);
				}
				else
				{
					$this->judgeVerb->update($delete, $code, $modreq->verbId);
					$this->acceptRequest->update($this->sessionId, $modreq->moderationId);
				}
			}
			if ($modreq->objectId && $judgmentO)
			{
				$this->interpretJudgment($judgmentO, $delete, $reject, $code);
				if ($reject)
				{
					$this->absolveObject->update($modreq->objectId);
					$this->rejectRequest->update($this->sessionId, $modreq->moderationId);
				}
				else
				{
					$this->judgeObject->update($delete, $code, $modreq->objectId);
					$this->acceptRequest->update($this->sessionId, $modreq->moderationId);
				}
			}
			return true;
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
		}
	}
	
	private function interpretJudgment(?string $judgment, bool &$delete, bool &$reject, ?string &$code)
	{
		$reject = false;
		$delete = false;
		switch ($judgment)
		{
			case 'Valid':
				$reject = true;
				$code = null;
				break;
			case 'Non-noun':
			case 'Non-verb':
			case 'Gibberish':
			case 'Spam':
			case 'Evil':
				$delete = true;
				$code = $judgment;
				break;
			default: 		// Dubious, Spelling, etc
				$code = $judgment;
				break;
		}
	}

	public function sessionStats(int $sessionId)
	{
		try
		{
			$getStats = new Selector($this->marie, $this->log, 'i', '
			    WITH ses AS ( SELECT session_id, is_test, blocked_by, moderation_status, signature, ip_address, useragent, s.when_created, team_id, use_ct
			                    FROM sessions s LEFT JOIN teams USING (team_id)
			                   WHERE session_id = ? ),
			         sug AS ( SELECT count(suggestion_id) as idea_ct, min(when_suggested) as earliest_idea, max(when_suggested) AS latest_idea,
			                         sum(s.moderation_flag_ct + v.moderation_flag_ct + o.moderation_flag_ct) as word_flag_ct,
			                         sum(s.is_deleted + v.is_deleted + o.is_deleted) as word_deleted_ct,
			                         sum(s.shown_ct + v.shown_ct + o.shown_ct) AS word_shown_ct,
			                         sum(CASE WHEN s.moderation_status IS NULL OR s.is_deleted = true THEN 0 ELSE 1 END +
			                             CASE WHEN v.moderation_status IS NULL OR v.is_deleted = true THEN 0 ELSE 1 END +
			                             CASE WHEN o.moderation_status IS NULL OR o.is_deleted = true THEN 0 ELSE 1 END) AS word_moderated_ct
			                    FROM ses INNER JOIN
			                         suggestions g USING (session_id) INNER JOIN
			                         subjects s    USING (subject_id) INNER JOIN
			                         verbs v       USING (verb_id)    INNER JOIN
			                         objects o     USING (object_id) ),
			         pit AS ( SELECT count(pitch_id) AS pitch_ct, min(when_submitted) AS earliest_pitch, max(when_submitted) AS latest_pitch,
			                         sum(moderation_flag_ct) as pitch_flag_ct, sum(is_deleted) as pitch_deleted_ct, sum(shown_ct) AS pitch_shown_ct,
			                         sum(CASE WHEN p.moderation_status IS NULL OR p.is_deleted = true THEN 0 ELSE 1 END) AS pitch_moderated_ct
			                    FROM ses INNER JOIN
			                         pitches p USING (session_id) ),
			         req AS ( SELECT count(*) as total_reqs_ct, min(when_submitted) AS earliest_req, max(when_submitted) AS latest_req,
			                         SUM(CASE WHEN accepted_by IS NULL THEN 0 ELSE 1 END) AS accepted_reqs_ct,
			                         SUM(CASE WHEN rejected_by IS NULL THEN 0 ELSE 1 END) AS rejected_reqs_ct
			                    FROM ses INNER JOIN 
			                         moderations USING (session_id) )
			    SELECT blocked_by, is_test, moderation_status, signature, ip_address, useragent, when_created, team_id, use_ct,
			           idea_ct, earliest_idea, latest_idea, word_shown_ct, word_flag_ct, word_deleted_ct, word_moderated_ct,
			           pitch_ct, earliest_pitch, latest_pitch, pitch_shown_ct, pitch_flag_ct, pitch_deleted_ct, pitch_moderated_ct,
			           total_reqs_ct, earliest_req, latest_req, accepted_reqs_ct, rejected_reqs_ct
			      FROM ses, sug, pit, req');
	
			if ($getStats->select($sessionId))
			{
				$result = new SessionSummary();
				if (!$getStats->getRow($result->blockedBy, $result->isTest, $result->moderationStatus, $result->signature,
				                       $result->ipAddress, $result->userAgent, $result->whenCreated, $result->teamId, $result->teamUseCount,
				                       $result->ideasCount, $result->ideasEarliest, $result->ideasLatest, $result->wordsShownCount,
				                       $result->wordsFlaggedCount, $result->wordsDeletedCount, $result->wordsModeratedCount,
				                       $result->pitchesCount, $result->pitchesEarliest, $result->pitchesLatest, $result->pitchesShownCount,
				                       $result->pitchesFlaggedCount, $result->pitchesDeletedCount, $result->pitchesModeratedCount,
				                       $result->modRequestsCount, $result->modRequestsEarliest, $result->modRequestsLatest,
				                       $result->modRequestsAcceptedCount, $result->modRequestsRejectedCount))
					return null;
				return $result;
			}
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
		}
	}

	public function blockSession(int $sessionId, bool $block)
	{
		try
		{
			$setBlock = new Updater($this->marie, $this->log, 'ii',
			    'UPDATE sessions SET blocked_by = ? WHERE session_id = ?');
			return $setBlock->update($block ? $this->sessionId : null, $sessionId);
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
		}
	}

	public function purgeSession(int $sessionId)
	{
		try
		{
			$purgePitches = new Updater($this->marie, $this->log, 'i',
			    'UPDATE pitches SET is_deleted = true, moderation_status = \'Purged\'
			      WHERE session_id = ? AND is_deleted = false');
			$purgeSubjects = new Updater($this->marie, $this->log, 'ii',
			    'UPDATE subjects SET is_deleted = true, moderation_status = \'Purged\'
			      WHERE subject_id IN ( SELECT subject_id FROM suggestions WHERE session_id = ? )
			        AND NOT EXISTS ( SELECT 1 FROM suggestions
			                          WHERE subject_id = subjects.subject_id AND session_id <> ? )
			        AND is_deleted = false');
			$purgeVerbs = new Updater($this->marie, $this->log, 'ii',
			    'UPDATE verbs SET is_deleted = true, moderation_status = \'Purged\'
			      WHERE verb_id IN ( SELECT verb_id FROM suggestions WHERE session_id = ? )
			        AND NOT EXISTS ( SELECT 1 FROM suggestions
			                          WHERE verb_id = verbs.verb_id AND session_id <> ? )
			        AND is_deleted = false');
			$purgeObjects = new Updater($this->marie, $this->log, 'ii',
			    'UPDATE objects SET is_deleted = true, moderation_status = \'Purged\'
			      WHERE object_id IN ( SELECT object_id FROM suggestions WHERE session_id = ? )
			        AND NOT EXISTS ( SELECT 1 FROM suggestions
			                          WHERE object_id = objects.object_id AND session_id <> ? )
			        AND is_deleted = false');

			$this->marie->begin_transaction();
			$ret = $this->blockSession($sessionId, true) &&
			       $purgePitches->update($sessionId) && $purgeSubjects->update($sessionId, $sessionId) &&
			       $purgeVerbs->update($sessionId, $sessionId) && $purgeObjects->update($sessionId, $sessionId);
			if ($ret)
				$this->marie->commit();
			else
				throw new Exception("Purge failed, see log");
			return $ret;
		}
		catch (Throwable $ex)
		{
			$this->marie->rollback();			
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
