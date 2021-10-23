<?php
// If it were just the game, the database would be pretty simple.  But because we have to also support
// moderation and features for flagging garbage inputs, we have to track a bunch more detail.
// The moderation features are more complex than the gameplay.  See pitchgame tables.sql for data design.

require 'pitchdata.php';


// objects returned by PitchGameAdminConnection methods:

// A moderation request applies either to a pitch or to challenge words.
class ModerationRequest extends Pitch
{
	public ?int    $moderationId;
	public ?string $whenRequested;
	public ?int    $requestorSessionId;
	public ?int    $flagDupes;
	public ?string $whenPitched;
	public ?int    $pitchSessionId;
	public ?int    $pitchFlagCount;
	public ?string $whenSubject;		// when first submitted
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
}

class SessionSummary
{
	public ?int    $blockedBy;
	public ?bool   $isTest;
	public ?string $ipAddress;
	public ?string $userAgent;
	public ?string $signature;
	public ?string $whenCreated;
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

class HistoryPart
{
	public ?int     $shownCount;
	public ?int     $flagCount;
	public ?string  $modStatus;
	public ?bool    $deleted;
	public function live() { return $this->deleted === false && !$this->modStatus && $this->flagCount === 0; }
}

class HistoryEntry extends Pitch
{
	public ?string      $whenPosted;
	public ?int         $suggestionId;
	public ?int         $moderationId;
	public ?int         $acceptedBy;
	public ?int         $rejectedBy;
	public ?HistoryPart $p;
	public ?HistoryPart $s;
	public ?HistoryPart $v;
	public ?HistoryPart $o;
	public function deleted() { return $this->pitchId ? $this->p->deleted :
	                                   (!isset($this->s->deleted) || $this->s->deleted) &&
	                                   (!isset($this->v->deleted) || $this->v->deleted) &&
	                                   (!isset($this->o->deleted) || $this->o->deleted); }
}

class SuspiciousSession {
	public ?int    $sessionId;
	public ?string $signature;
	public ?string $whenLastUsed;
	public ?int    $deletions;
}


class PitchGameAdminConnection extends PitchGameConnection
{

	public function getRecentModerationRequests()
	{
		try
		{
			// Something has to be done about this monstrous query.  Would any part of it make a view useful to other queries?
			$getRequests = new Selector($this->marie, $this->log, '', '
			    WITH mrq AS ( SELECT m.moderation_id, m.session_id, m.when_submitted,
			                         p.pitch_id, s.subject_id, v.verb_id, o.object_id,
			                         min(ss.suggestion_id) AS subj_sugg, count(DISTINCT ss.suggestion_id) AS subj_dupe,
			                         min(sv.suggestion_id) AS verb_sugg, count(DISTINCT sv.suggestion_id) AS verb_dupe,
			                         min(so.suggestion_id) AS obj_sugg,  count(DISTINCT so.suggestion_id) AS obj_dupe
			                    FROM moderations m LEFT JOIN
			                         pitches p  ON m.pitch_id   = p.pitch_id   AND p.is_deleted = FALSE
			                                   AND p.moderation_status IS NULL AND p.moderation_flag_ct > 0 LEFT JOIN
			                         subjects s ON m.subject_id = s.subject_id AND s.is_deleted = FALSE
			                                   AND s.moderation_status IS NULL AND s.moderation_flag_ct > 0 LEFT JOIN
			                         verbs v    ON m.verb_id = v.verb_id    AND v.is_deleted = FALSE
			                                   AND v.moderation_status IS NULL AND v.moderation_flag_ct > 0 LEFT JOIN
			                         objects o  ON m.object_id  = o.object_id  AND o.is_deleted = FALSE
			                                   AND o.moderation_status IS NULL AND o.moderation_flag_ct > 0 LEFT JOIN
			                         suggestions ss ON s.subject_id = ss.subject_id LEFT JOIN
			                         suggestions sv ON v.verb_id    = sv.verb_id    LEFT JOIN
			                         suggestions so ON o.object_id  = so.object_id
			                   GROUP BY m.moderation_id, m.session_id, m.when_submitted, p.pitch_id, s.subject_id, v.verb_id, o.object_id ),
			         -- this flattens multiple complaints about the same word or pitch, but is not able to flatten cases where
			         -- one complaint mentions a single word and another mentions the same word along with one or two others:
			         muq as ( SELECT pitch_id, session_id, when_submitted, subject_id, verb_id, object_id,
			                         subj_sugg, verb_sugg, obj_sugg, subj_dupe, verb_dupe, obj_dupe,
			                         count(DISTINCT moderation_id) AS flag_dupe,
			                         max(moderation_id) AS last_req_id, min(moderation_id) AS first_req_id
			                    FROM mrq WHERE COALESCE(pitch_id, subject_id, verb_id, object_id) IS NOT NULL
			                   GROUP BY pitch_id, subject_id, verb_id, object_id,
			                            subj_sugg, verb_sugg, obj_sugg, subj_dupe, verb_dupe, obj_dupe ),
			         ten as ( SELECT last_req_id AS moderation_id,
			                         session_id, when_submitted, pitch_id, subject_id, verb_id, object_id,
			                         subj_sugg, verb_sugg, obj_sugg, subj_dupe, verb_dupe, obj_dupe, flag_dupe
			                    FROM muq
			                   ORDER BY last_req_id - first_req_id DESC, last_req_id DESC
			                   LIMIT 10 )
			    SELECT ten.moderation_id, ten.when_submitted, ten.session_id AS requestor_session_id, ten.flag_dupe,
			           p.session_id AS pitch_session_id, ss.session_id AS subject_session_id,
			           sv.session_id AS verb_session_id, so.session_id AS object_session_id,
			           p.moderation_flag_ct AS pitch_flag_ct, s.moderation_flag_ct AS subj_flag_ct,
			           v.moderation_flag_ct AS verb_flag_ct,  o.moderation_flag_ct AS obj_flag_ct,
			           p.when_submitted AS when_pitch, ss.when_suggested AS when_subj,
			           sv.when_suggested AS when_verb, so.when_suggested AS when_obj,
			           p.pitch_id, p.title, p.pitch, p.signature, ten.subj_dupe, ten.verb_dupe, ten.obj_dupe,
			           -- ifnull(ps.subject_id, s.subject_id) AS subject_id, ifnull(ps.word, s.word) AS subject,
			           -- ifnull(pv.verb_id,    v.verb_id)    AS verb_id,    ifnull(pv.word, v.word) AS verb,
			           -- ifnull(po.object_id,  o.object_id)  AS object_id,  ifnull(po.word, o.word) AS object
			           s.subject_id, s.word AS subject, v.verb_id, v.word AS verb, o.object_id, o.word AS object
			      FROM ten LEFT JOIN
			           pitches p   ON ten.pitch_id = p.pitch_id    LEFT JOIN
			           -- subjects ps ON p.subject_id = ps.subject_id LEFT JOIN
			           -- verbs pv    ON p.verb_id    = pv.verb_id    LEFT JOIN
			           -- objects po  ON p.object_id  = po.object_id  LEFT JOIN
			           subjects s ON ten.subject_id = s.subject_id LEFT JOIN
			           verbs v    ON ten.verb_id    = v.verb_id    LEFT JOIN
			           objects o  ON ten.object_id  = o.object_id  LEFT JOIN
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
			$getHistory = new Selector($this->marie, $this->log, 'iii', '
			    WITH ide AS ( SELECT when_suggested AS when_posted, suggestion_id, subject_id, verb_id, object_id,
			                         s.word AS subject, v.word AS verb, o.word AS object,
			                         s.shown_ct AS s_shown_ct, v.shown_ct AS v_shown_ct, o.shown_ct AS o_shown_ct,
			                         s.moderation_flag_ct AS s_flag_ct, v.moderation_flag_ct AS v_flag_ct, o.moderation_flag_ct AS o_flag_ct,
			                         s.moderation_status AS s_mod_status, v.moderation_status AS v_mod_status, o.moderation_status AS o_mod_status,
			                         s.is_deleted AS s_is_deleted, v.is_deleted AS v_is_deleted, o.is_deleted AS o_is_deleted,
			                         NULL AS pitch_id, NULL AS title, NULL AS pitch, NULL AS signature,
			                         NULL AS p_shown_ct, NULL AS p_flag_ct, NULL AS p_mod_status, NULL AS p_is_deleted,
			                         NULL AS moderation_id, NULL AS accepted_by, NULL AS rejected_by
			                    FROM suggestions JOIN
			                         subjects s USING (subject_id) JOIN
			                         verbs v    USING (verb_id) JOIN
			                         objects o  USING (object_id)
			                   WHERE session_id = ? ),
			         pit AS ( SELECT when_submitted AS when_posted, NULL AS suggestion_id, NULL AS subject_id, NULL AS verb_id, NULL AS object_id,
			                         NULL AS subject, NULL AS verb, NULL AS object, NULL AS s_shown_ct, NULL AS v_shown_ct, NULL AS o_shown_ct,
			                         NULL AS s_flag_ct, NULL AS v_flag_ct, NULL AS o_flag_ct, NULL AS s_mod_status, NULL AS v_mod_status, NULL AS o_mod_status,
			                         NULL AS s_is_deleted, NULL AS v_us_deleted, NULL AS o_is_deleted,
			                         pitch_id, title, pitch, signature,
			                         shown_ct AS p_shown_ct, moderation_flag_ct AS p_flag_ct,
			                         moderation_status AS p_mod_status, is_deleted AS p_is_deleted,
			                         NULL AS moderation_id, NULL AS accepted_by, NULL AS rejected_by
			                    FROM pitches WHERE session_id = ? ),
			         mor AS ( SELECT m.when_submitted AS when_posted, NULL AS suggestion_id, s.subject_id, v.verb_id, o.object_id,
			                         s.word AS subject, v.word AS verb, o.word AS object,
			                         NULL AS s_shown_ct, NULL AS v_shown_ct, NULL AS o_shown_ct, NULL AS s_flag_ct, NULL AS v_flag_ct, NULL AS o_flag_ct,
			                         s.moderation_status AS s_mod_status, v.moderation_status AS v_mod_status, o.moderation_status AS o_mod_status,
			                         s.is_deleted AS s_is_deleted, v.is_deleted AS v_is_deleted, o.is_deleted AS o_is_deleted,
			                         p.pitch_id, p.title, p.pitch, p.signature,
			                         NULL AS p_shown_ct, NULL AS p_flag_ct, p.moderation_status AS p_mod_status, p.is_deleted AS p_is_deleted,
			                         m.moderation_id, m.accepted_by, m.rejected_by
			                    FROM moderations m LEFT JOIN
			                         pitches p  ON m.pitch_id   = p.pitch_id   LEFT JOIN
			                         subjects s ON m.subject_id = s.subject_id LEFT JOIN
			                         verbs v    ON m.verb_id    = v.verb_id    LEFT JOIN
			                         objects o  ON m.object_id  = o.object_id
			                   WHERE m.session_id = ? )
			    SELECT * FROM pit UNION SELECT * FROM ide UNION SELECT * from mor
                 ORDER BY when_posted DESC');

			$getHistory->select($sessionId, $sessionId, $sessionId);
			$results = [];
			do {
				$h = new HistoryEntry();
				$h->p = new HistoryPart();
				$h->s = new HistoryPart();
				$h->v = new HistoryPart();
				$h->o = new HistoryPart();
				$gotten = $getHistory->getRow($h->whenPosted, $h->suggestionId, $h->subjectId, $h->verbId, $h->objectId,
				                              $h->subject, $h->verb, $h->object,
				                              $h->s->shownCount, $h->v->shownCount, $h->o->shownCount,
				                              $h->s->flagCount, $h->v->flagCount, $h->o->flagCount,
				                              $h->s->modStatus, $h->v->modStatus, $h->o->modStatus,
				                              $h->s->deleted, $h->v->deleted, $h->o->deleted,
				                              $h->pitchId, $h->title, $h->pitch, $h->signature,
				                              $h->p->shownCount, $h->p->flagCount, $h->p->modStatus, $h->p->deleted,
				                              $h->moderationId, $h->acceptedBy, $h->rejectedBy);
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
				    'UPDATE pitches SET moderation_flag_ct = 0, moderation_status = \'Valid\' WHERE pitch_id = ?');
				$this->judgePitch = new Updater($this->marie, $this->log, 'isi',
				    'UPDATE pitches SET is_deleted = ?, moderation_status = ? WHERE pitch_id = ?');
				$this->absolveSubject = new Updater($this->marie, $this->log, 'i',
				    'UPDATE subjects SET moderation_flag_ct = 0, moderation_status = \'Valid\' WHERE subject_id = ?');
				$this->judgeSubject = new Updater($this->marie, $this->log, 'isi',
				    'UPDATE subjects SET is_deleted = ?, moderation_status = ? WHERE subject_id = ?');
				$this->absolveVerb = new Updater($this->marie, $this->log, 'i',
				    'UPDATE verbs SET moderation_flag_ct = 0, moderation_status = \'Valid\' WHERE verb_id = ?');
				$this->judgeVerb = new Updater($this->marie, $this->log, 'isi',
				    'UPDATE verbs SET is_deleted = ?, moderation_status = ? WHERE verb_id = ?');
				$this->absolveObject = new Updater($this->marie, $this->log, 'i',
				    'UPDATE objects SET moderation_flag_ct = 0, moderation_status = \'Valid\' WHERE object_id = ?');
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
				$this->interpretJudgment($judgmentP, $delete, $reject);
				if ($reject)
				{
					$this->absolvePitch->update($modreq->pitchId);
					$this->rejectRequest->update($this->sessionId, $modreq->moderationId);
				}
				else
				{
					$this->judgePitch->update($delete, $judgmentP, $modreq->pitchId);
					$this->acceptRequest->update($this->sessionId, $modreq->moderationId);
				}
			}
			if ($modreq->subjectId && $judgmentS)
			{
				$this->interpretJudgment($judgmentS, $delete, $reject);
				if ($reject)
				{
					$this->absolveSubject->update($modreq->subjectId);
					$this->rejectRequest->update($this->sessionId, $modreq->moderationId);
				}
				else
				{
					$this->judgeSubject->update($delete, $judgmentS, $modreq->subjectId);
					$this->acceptRequest->update($this->sessionId, $modreq->moderationId);
				}
			}
			if ($modreq->verbId && $judgmentV)
			{
				$this->interpretJudgment($judgmentV, $delete, $reject);
				if ($reject)
				{
					$this->absolveVerb->update($modreq->verbId);
					$this->rejectRequest->update($this->sessionId, $modreq->moderationId);
				}
				else
				{
					$this->judgeVerb->update($delete, $judgmentV, $modreq->verbId);
					$this->acceptRequest->update($this->sessionId, $modreq->moderationId);
				}
			}
			if ($modreq->objectId && $judgmentO)
			{
				$this->interpretJudgment($judgmentO, $delete, $reject);
				if ($reject)
				{
					$this->absolveObject->update($modreq->objectId);
					$this->rejectRequest->update($this->sessionId, $modreq->moderationId);
				}
				else
				{
					$this->judgeObject->update($delete, $judgmentO, $modreq->objectId);
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
	
	private function interpretJudgment(?string $judgment, bool &$delete, bool &$reject)
	{
		$reject = false;
		$delete = false;
		switch ($judgment)
		{
			case 'Valid':
				$reject = true;
				break;
			case 'Non-noun':
			case 'Non-verb':
			case 'Gibberish':
			case 'Spam':
			case 'Evil':
				$delete = true;
				break;
			default: 		// Dubious, Misspelled, etc don't set either reject or delete
				break;
		}
	}

	public function sessionStats(int $sessionId)
	{
		try
		{
			$getStats = new Selector($this->marie, $this->log, 'i', '
			    WITH ses AS ( SELECT session_id, is_test, blocked_by, signature, ip_address, useragent, s.when_created
			                    FROM sessions s      -- later, add team stats
			                   WHERE session_id = ? ),
			         sug AS ( SELECT count(suggestion_id) as idea_ct, min(when_suggested) as earliest_idea, max(when_suggested) AS latest_idea,
			                         sum(s.moderation_flag_ct + v.moderation_flag_ct + o.moderation_flag_ct) as word_flag_ct,
			                         sum(s.is_deleted + v.is_deleted + o.is_deleted) as word_deleted_ct,
			                         sum(s.shown_ct + v.shown_ct + o.shown_ct) AS word_shown_ct,
			                         sum(CASE WHEN ifnull(s.moderation_status, \'Valid\') = \'Valid\' OR s.is_deleted = true THEN 0 ELSE 1 END +
			                             CASE WHEN ifnull(v.moderation_status, \'Valid\') = \'Valid\' OR v.is_deleted = true THEN 0 ELSE 1 END +
			                             CASE WHEN ifnull(o.moderation_status, \'Valid\') = \'Valid\' OR o.is_deleted = true THEN 0 ELSE 1 END) AS word_moderated_ct
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
			    SELECT blocked_by, is_test, signature, ip_address, useragent, when_created,
			           idea_ct, earliest_idea, latest_idea, word_shown_ct, word_flag_ct, word_deleted_ct, word_moderated_ct,
			           pitch_ct, earliest_pitch, latest_pitch, pitch_shown_ct, pitch_flag_ct, pitch_deleted_ct, pitch_moderated_ct,
			           total_reqs_ct, earliest_req, latest_req, accepted_reqs_ct, rejected_reqs_ct
			      FROM ses, sug, pit, req');
	
			if ($getStats->select($sessionId))
			{
				$result = new SessionSummary();
				if (!$getStats->getRow($result->blockedBy, $result->isTest, $result->signature,
				                       $result->ipAddress, $result->userAgent, $result->whenCreated,
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
			    'UPDATE sessions SET blocked_by = ?, when_last_reviewed = now() WHERE session_id = ?');
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

	public function getSuspiciousSessions(int $ageInDays)
	{
		try
		{
			$findUsers = new Selector($this->marie, $this->log, 'i', '
			    WITH words AS ( SELECT session_id, signature, when_last_used,
			                           sum(s.is_deleted + v.is_deleted + o.is_deleted) AS word_deletions
			                      FROM sessions INNER JOIN
			                           suggestions g USING (session_id) INNER JOIN
			                           subjects s    USING (subject_id) INNER JOIN
			                           verbs v       USING (verb_id)    INNER JOIN
			                           objects o     USING (object_id) 
			                     WHERE blocked_by IS NULL AND datediff(now(), when_last_used) <= ?
			                     GROUP BY session_id, signature, when_last_used )
			    SELECT session_id, words.signature, when_last_used, word_deletions + sum(ifnull(p.is_deleted, 0)) AS deletions
			      FROM words LEFT OUTER JOIN pitches p USING (session_id)
			     WHERE word_deletions > 0 OR p.is_deleted <> 0
			     GROUP BY session_id, signature, when_last_used
			     ORDER BY deletions DESC, when_last_used DESC');

			$results = [];
			$findUsers->select($ageInDays);
			do {
				$u = new SuspiciousSession();
				$gotten = $findUsers->getRow($u->sessionId, $u->signature, $u->whenLastUsed, $u->deletions);
				if ($gotten)
					$results[] = $u;
			} while ($gotten);
			return $results;
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
		}
	}
}
?>