<?php
// If it were just the game, the database would be pretty simple.  But because we have to also support
// moderation and features for flagging garbage inputs, we have to track a bunch more detail.
// The moderation features are more complex than the gameplay.  See pitchgame tables.sql for data design.

require 'pitchdata.php';


// objects returned by PitchGameAdminConnection methods:

class Moderated
{
	public ?string $when;
	public ?int    $sessionId;
	public ?string $nickname;
	public ?int    $flagCount;
	public ?int    $dupes;
}

// A moderation request applies either to a pitch or to challenge words.
class ModerationRequest
{
	public ?int       $moderationId;
	public ?string    $whenRequested;
	public ?int       $requestorSessionId;
	public ?string    $requestorName;
	public ?int       $flagDupes;
	public ?Pitch     $p;
	public ?Moderated $pit;
	public ?Moderated $sub;
	public ?Moderated $vrb;
	public ?Moderated $obj;
}

class SessionSummary
{
	public ?int    $blockedBy;
	public ?bool   $isTest;
	public ?bool   $isDebugger;
	public ?string $ipAddress;
	public ?string $userAgent;
	public ?string $nickname;
	public ?string $signature;
	public ?string $whenCreated;
	public ?string $whenLastUsed;
	public ?string $whenLastReviewed;
	public ?float  $whenLastReviewedUnixTime;
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
	public ?int    $modReqsCount;
	public ?string $modReqsEarliest;
	public ?string $modReqsLatest;
	public ?int    $modReqsAcceptedCount;
	public ?int    $modReqsRejectedCount;
	public ?int    $modReqsPendingCount;
	public ?int    $modReqsSplitCount;
}

class HistoryPart
{
	public ?int     $shownCount;
	public ?int     $flagCount;
	public ?string  $modStatus;
	public ?bool    $deleted;
	public function live() { return $this->deleted === false && !$this->modStatus && $this->flagCount === 0; }
}

class HistoryEntry
{
	public ?string      $whenPosted;
	public ?float       $whenPostedUnixTime;
	public ?int         $suggestionId;
	public ?int         $moderationId;
	public ?int         $acceptedBy;
	public ?int         $rejectedBy;
	public ?string      $rejectNickname;
	public ?Pitch       $what;
	public ?HistoryPart $p;
	public ?HistoryPart $s;
	public ?HistoryPart $v;
	public ?HistoryPart $o;
	public function deleted() { return $this->what->pitchId ? $this->p->deleted :
	                                   (!isset($this->s->deleted) || $this->s->deleted) &&
	                                   (!isset($this->v->deleted) || $this->v->deleted) &&
	                                   (!isset($this->o->deleted) || $this->o->deleted); }
}

class SuspiciousSession {
	public ?int    $sessionId;
	public ?string $nickname;
	public ?string $signature;
	public ?string $whenLastUsed;
	public ?int    $deletions;
}


class PitchGameAdminConnection extends PitchGameConnection
{

	public function getRecentModerationRequests(): ?array
	{
		try
		{
			// Something has to be done about this monstrous query.  Would any part of it make a view useful to other queries?
			$getRequests = new Selector($this->marie, $this->log, '', '
			    WITH mrq AS ( SELECT m.moderation_id, p.pitch_id, s.subject_id, v.verb_id, o.object_id,
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
			                   GROUP BY m.moderation_id, p.pitch_id, s.subject_id, v.verb_id, o.object_id ),
			         -- this flattens multiple complaints about the same word or pitch, but is not able to flatten cases where
			         -- one complaint mentions a single word and another mentions the same word along with one or two others:
			         muq as ( SELECT pitch_id, subject_id, verb_id, object_id,
			                         subj_sugg, verb_sugg, obj_sugg, subj_dupe, verb_dupe, obj_dupe,
			                         count(DISTINCT moderation_id) AS flag_dupe,
			                         max(moderation_id) AS last_req_id, min(moderation_id) AS first_req_id
			                    FROM mrq WHERE COALESCE(pitch_id, subject_id, verb_id, object_id) IS NOT NULL
			                   GROUP BY pitch_id, subject_id, verb_id, object_id,
			                            subj_sugg, verb_sugg, obj_sugg, subj_dupe, verb_dupe, obj_dupe ),
			         ten as ( SELECT last_req_id, pitch_id, subject_id, verb_id, object_id,
			                         subj_sugg, verb_sugg, obj_sugg, subj_dupe, verb_dupe, obj_dupe, flag_dupe
			                    FROM muq
			                   ORDER BY last_req_id - first_req_id DESC, last_req_id DESC
			                   LIMIT 10 )
			    SELECT m.moderation_id, m.when_submitted, n.session_id AS req_session_id, n.nickname AS req_nickname, ten.flag_dupe,
			           p.session_id AS pitch_session_id, ss.session_id AS subject_session_id,
			           sv.session_id AS verb_session_id, so.session_id AS object_session_id,
					   np.nickname AS pitch_name, ns.nickname AS subject_name, nv.nickname AS verb_name, no.nickname AS object_name,
			           p.moderation_flag_ct AS pitch_flag_ct, s.moderation_flag_ct AS subj_flag_ct,
			           v.moderation_flag_ct AS verb_flag_ct,  o.moderation_flag_ct AS obj_flag_ct,
			           p.when_submitted AS when_pitch, ss.when_suggested AS when_subj,
			           sv.when_suggested AS when_verb, so.when_suggested AS when_obj,
			           ten.subj_dupe, ten.verb_dupe, ten.obj_dupe, p.pitch_id, p.title, p.pitch, p.signature,
			           -- ifnull(ps.subject_id, s.subject_id) AS subject_id, ifnull(ps.word, s.word) AS subject,
			           -- ifnull(pv.verb_id,    v.verb_id)    AS verb_id,    ifnull(pv.word, v.word) AS verb,
			           -- ifnull(po.object_id,  o.object_id)  AS object_id,  ifnull(po.word, o.word) AS object
			           s.subject_id, s.word AS subject, v.verb_id, v.word AS verb, o.object_id, o.word AS object
			      FROM ten INNER JOIN
				       moderations m ON ten.last_req_id = m.moderation_id INNER JOIN
				       sessions n    ON m.session_id = n.session_id LEFT JOIN
			           pitches p     ON ten.pitch_id = p.pitch_id   LEFT JOIN
			           -- subjects ps ON p.subject_id = ps.subject_id  LEFT JOIN
			           -- verbs pv    ON p.verb_id    = pv.verb_id     LEFT JOIN
			           -- objects po  ON p.object_id  = po.object_id   LEFT JOIN
					   sessions np ON p.session_id = np.session_id LEFT JOIN
			           subjects s ON ten.subject_id = s.subject_id LEFT JOIN
			           verbs v    ON ten.verb_id    = v.verb_id    LEFT JOIN
			           objects o  ON ten.object_id  = o.object_id  LEFT JOIN
			           suggestions ss ON ten.subj_sugg = ss.suggestion_id LEFT JOIN
					   sessions ns    ON ss.session_id = ns.session_id    LEFT JOIN
			           suggestions sv ON ten.verb_sugg = sv.suggestion_id LEFT JOIN
					   sessions nv    ON sv.session_id = nv.session_id    LEFT JOIN
			           suggestions so ON ten.obj_sugg  = so.suggestion_id LEFT JOIN
					   sessions no    ON so.session_id = no.session_id');

			$getRequests->select();
			$results = [];
			do {
				$rq = new ModerationRequest();
				$rq->p = new Pitch();
				$rq->p->c = new Challenge();
				$rq->pit = new Moderated();
				$rq->sub = new Moderated();
				$rq->vrb = new Moderated();
				$rq->obj = new Moderated();
				$gotten = $getRequests->getRow($rq->moderationId, $rq->whenRequested, $rq->requestorSessionId, $rq->requestorName, $rq->flagDupes,
				                               $rq->pit->sessionId, $rq->sub->sessionId, $rq->vrb->sessionId, $rq->obj->sessionId,
				                               $rq->pit->nickname,  $rq->sub->nickname,  $rq->vrb->nickname,  $rq->obj->nickname,
				                               $rq->pit->flagCount, $rq->sub->flagCount, $rq->vrb->flagCount, $rq->obj->flagCount,
				                               $rq->pit->when,      $rq->sub->when,      $rq->vrb->when,      $rq->obj->when,
				                               /*pit->dupes null,*/ $rq->sub->dupes,     $rq->vrb->dupes,     $rq->obj->dupes,
				                               $rq->p->pitchId, $rq->p->title, $rq->p->pitch, $rq->p->signature,
				                               $rq->p->c->subjectId, $rq->p->c->subject, $rq->p->c->verbId, $rq->p->c->verb,
				                               $rq->p->c->objectId, $rq->p->c->object);
				if ($gotten)
					$results[] = $rq;
			} while ($gotten);
			return $results;
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
			return null;    // PHP docs claim no return means null is returned, but this is not true enough
		}
	}

	public function sessionStats(int $sessionId): ?SessionSummary
	{
		try
		{
			$getStats = new Selector($this->marie, $this->log, 'i', '
			    WITH ses AS ( SELECT session_id, is_test, has_debug_access, blocked_by, nickname, signature, ip_address, useragent, when_created, when_last_used,
			                         when_last_reviewed, cast(unix_timestamp(when_last_reviewed) AS double) AS when_last_reviewed_unix
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
			                         sum(CASE WHEN accepted_by IS NULL THEN 0 ELSE 1 END) AS accepted_reqs_ct,
			                         sum(CASE WHEN rejected_by IS NULL THEN 0 ELSE 1 END) AS rejected_reqs_ct,
			                         sum(CASE WHEN accepted_by IS NULL AND rejected_by IS NULL THEN 1 ELSE 0 END) pending_reqs_ct
			                    FROM ses INNER JOIN 
			                         moderations USING (session_id) )
			    SELECT blocked_by, is_test, has_debug_access, nickname, signature, ip_address, useragent,
			           when_created, when_last_used, when_last_reviewed, when_last_reviewed_unix,
			           idea_ct, earliest_idea, latest_idea, word_shown_ct, word_flag_ct, word_deleted_ct, word_moderated_ct,
			           pitch_ct, earliest_pitch, latest_pitch, pitch_shown_ct, pitch_flag_ct, pitch_deleted_ct, pitch_moderated_ct,
			           total_reqs_ct, earliest_req, latest_req, accepted_reqs_ct, rejected_reqs_ct, pending_reqs_ct
			      FROM ses, sug, pit, req');
	
			if ($getStats->select($sessionId))
			{
				$result = new SessionSummary();
				if (!$getStats->getRow($result->blockedBy, $result->isTest, $result->isDebugger, $result->nickname, $result->signature, $result->ipAddress, $result->userAgent,
				                       $result->whenCreated, $result->whenLastUsed, $result->whenLastReviewed, $result->whenLastReviewedUnixTime,
				                       $result->ideasCount, $result->ideasEarliest, $result->ideasLatest, $result->wordsShownCount,
				                       $result->wordsFlaggedCount, $result->wordsDeletedCount, $result->wordsModeratedCount,
				                       $result->pitchesCount, $result->pitchesEarliest, $result->pitchesLatest, $result->pitchesShownCount,
				                       $result->pitchesFlaggedCount, $result->pitchesDeletedCount, $result->pitchesModeratedCount,
				                       $result->modReqsCount, $result->modReqsEarliest, $result->modReqsLatest,
				                       $result->modReqsAcceptedCount, $result->modReqsRejectedCount, $result->modReqsPendingCount))
					return null;
				$result->modReqsSplitCount = $result->modReqsAcceptedCount + $result->modReqsRejectedCount + $result->modReqsPendingCount - $result->modReqsCount;
				return $result;
			}
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
			return null;
		}
	}

	public function getSessionHistory($sessionId): ?array
	{
		try
		{
			$getHistory = new Selector($this->marie, $this->log, 'iii', '
			    WITH ide AS ( SELECT when_suggested AS when_posted, cast(unix_timestamp(when_suggested) AS double) AS when_posted_unix,
			                         suggestion_id, subject_id, verb_id, object_id, s.word AS subject, v.word AS verb, o.word AS object,
			                         s.shown_ct AS s_shown_ct, v.shown_ct AS v_shown_ct, o.shown_ct AS o_shown_ct,
			                         s.moderation_flag_ct AS s_flag_ct, v.moderation_flag_ct AS v_flag_ct, o.moderation_flag_ct AS o_flag_ct,
			                         s.moderation_status AS s_mod_status, v.moderation_status AS v_mod_status, o.moderation_status AS o_mod_status,
			                         s.is_deleted AS s_is_deleted, v.is_deleted AS v_is_deleted, o.is_deleted AS o_is_deleted,
			                         NULL AS pitch_id, NULL AS title, NULL AS pitch, NULL AS signature,
			                         NULL AS p_shown_ct, NULL AS p_flag_ct, NULL AS p_mod_status, NULL AS p_is_deleted,
			                         NULL AS moderation_id, NULL AS accepted_by, NULL AS rejected_by, NULL AS rejected_name
			                    FROM suggestions JOIN
			                         subjects s USING (subject_id) JOIN
			                         verbs v    USING (verb_id) JOIN
			                         objects o  USING (object_id)
			                   WHERE session_id = ? ),
			         pit AS ( SELECT when_submitted AS when_posted, cast(unix_timestamp(when_submitted) AS double) AS when_posted_unix,
			                         NULL AS suggestion_id, NULL AS subject_id, NULL AS verb_id, NULL AS object_id,
			                         NULL AS subject, NULL AS verb, NULL AS object,
			                         NULL AS s_shown_ct, NULL AS v_shown_ct, NULL AS o_shown_ct,
			                         NULL AS s_flag_ct, NULL AS v_flag_ct, NULL AS o_flag_ct,
			                         NULL AS s_mod_status, NULL AS v_mod_status, NULL AS o_mod_status,
			                         NULL AS s_is_deleted, NULL AS v_us_deleted, NULL AS o_is_deleted,
			                         pitch_id, title, pitch, signature,
			                         shown_ct AS p_shown_ct, moderation_flag_ct AS p_flag_ct,
			                         moderation_status AS p_mod_status, is_deleted AS p_is_deleted,
			                         NULL AS moderation_id, NULL AS accepted_by, NULL AS rejected_by, NULL AS rejected_name
			                    FROM pitches WHERE session_id = ? ),
			         mor AS ( SELECT m.when_submitted AS when_posted, cast(unix_timestamp(m.when_submitted) AS double) AS when_posted_unix,
			                         NULL AS suggestion_id, s.subject_id, v.verb_id, o.object_id, s.word AS subject, v.word AS verb, o.word AS object,
			                         NULL AS s_shown_ct, NULL AS v_shown_ct, NULL AS o_shown_ct, NULL AS s_flag_ct, NULL AS v_flag_ct, NULL AS o_flag_ct,
			                         s.moderation_status AS s_mod_status, v.moderation_status AS v_mod_status, o.moderation_status AS o_mod_status,
			                         s.is_deleted AS s_is_deleted, v.is_deleted AS v_is_deleted, o.is_deleted AS o_is_deleted,
			                         p.pitch_id, p.title, p.pitch, p.signature,
			                         NULL AS p_shown_ct, NULL AS p_flag_ct, p.moderation_status AS p_mod_status, p.is_deleted AS p_is_deleted,
			                         m.moderation_id, m.accepted_by, m.rejected_by, n.nickname AS rejected_name
			                    FROM moderations m LEFT JOIN
 			                         sessions n ON m.rejected_by = n.session_id LEFT JOIN
			                         pitches p  ON m.pitch_id    = p.pitch_id   LEFT JOIN
			                         subjects s ON m.subject_id  = s.subject_id LEFT JOIN
			                         verbs v    ON m.verb_id     = v.verb_id    LEFT JOIN
			                         objects o  ON m.object_id   = o.object_id
			                   WHERE m.session_id = ? )
			    SELECT * FROM pit UNION SELECT * FROM ide UNION SELECT * from mor
			     ORDER BY when_posted DESC');

			$getHistory->select($sessionId, $sessionId, $sessionId);
			$results = [];
			do {
				$h = new HistoryEntry();
				$h->what = new Pitch();
				$h->what->c = new Challenge();
				$h->p = new HistoryPart();
				$h->s = new HistoryPart();
				$h->v = new HistoryPart();
				$h->o = new HistoryPart();
				$gotten = $getHistory->getRow($h->whenPosted, $h->whenPostedUnixTime, $h->suggestionId,
				                              $h->what->c->subjectId, $h->what->c->verbId, $h->what->c->objectId,
				                              $h->what->c->subject, $h->what->c->verb, $h->what->c->object,
				                              $h->s->shownCount, $h->v->shownCount, $h->o->shownCount,
				                              $h->s->flagCount, $h->v->flagCount, $h->o->flagCount,
				                              $h->s->modStatus, $h->v->modStatus, $h->o->modStatus,
				                              $h->s->deleted, $h->v->deleted, $h->o->deleted,
				                              $h->what->pitchId, $h->what->title, $h->what->pitch, $h->what->signature,
				                              $h->p->shownCount, $h->p->flagCount, $h->p->modStatus, $h->p->deleted,
				                              $h->moderationId, $h->acceptedBy, $h->rejectedBy, $h->rejectNickname);
				if ($gotten)
					$results[] = $h;
			} while ($gotten);
			return $results;
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
			return null;
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

	public function saveJudgment(ModerationRequest $modreq, ?string $judgmentP, ?string $judgmentS, ?string $judgmentV, ?string $judgmentO): bool
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
			}
			$this->applyJudgment($judgmentP, $modreq->p->pitchId,      $modreq->moderationId, $this->absolvePitch,   $this->judgePitch);
			$this->applyJudgment($judgmentS, $modreq->p->c->subjectId, $modreq->moderationId, $this->absolveSubject, $this->judgeSubject);
			$this->applyJudgment($judgmentV, $modreq->p->c->verbId,    $modreq->moderationId, $this->absolveVerb,    $this->judgeVerb);
			$this->applyJudgment($judgmentO, $modreq->p->c->objectId,  $modreq->moderationId, $this->absolveObject,  $this->judgeObject);
			return true;
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
			return false;
		}
	}

	private ?Updater $acceptRequest = null;
	private ?Updater $rejectRequest;

	private function applyJudgment(?string $judgment, ?int $id, ?int $modId, Updater $absolver, Updater $judger)
	{
		if (!$id || !$judgment)
			return;
		if (!$this->acceptRequest)
		{
			// acceptRequest and rejectRequest may both be used; if so, leave both fields set to indicate mixed outcome
			$this->acceptRequest = new Updater($this->marie, $this->log, 'ii',
			    'UPDATE moderations SET accepted_by = ? WHERE moderation_id = ?');
			$this->rejectRequest = new Updater($this->marie, $this->log, 'ii',
			    'UPDATE moderations SET rejected_by = ? WHERE moderation_id = ?');
		}
		switch ($judgment)
		{
			case 'Valid':
				$reject = true;
				$delete = false;
				break;
			case 'Non-noun':
			case 'Non-verb':
			case 'Gibberish':
			case 'Spam':
			case 'Evil':
			case 'Hacking':
				$reject = false;
				$delete = true;
				break;
			default: 		// Dubious, Misspelled, etc
				$reject = false;
				$delete = false;
				break;
		}
		if ($reject)
		{
			$absolver->update($id);
			$this->rejectRequest->update($this->sessionId, $modId);
		}
		else
		{
			$judger->update($delete, $judgment, $id);
			$this->acceptRequest->update($this->sessionId, $modId);
		}
	}

	public function blockSession(int $sessionId, bool $block): bool
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
			return false;
		}
	}

	public function purgeSession(int $sessionId): bool
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
			$undoModSubjects = new Updater($this->marie, $this->log, 'i',
			    'UPDATE subjects SET moderation_flag_ct = moderation_flag_ct - 1
			      WHERE moderation_flag_ct > 0 AND moderation_status IS NULL AND subject_id IN
			            ( SELECT subject_id FROM moderations
			               WHERE accepted_by IS NULL AND rejected_by IS NULL AND session_id = ? )');
			$undoModVerbs = new Updater($this->marie, $this->log, 'i',
			    'UPDATE verbs SET moderation_flag_ct = moderation_flag_ct - 1
			      WHERE moderation_flag_ct > 0 AND moderation_status IS NULL AND verb_id IN
			            ( SELECT verb_id FROM moderations
			               WHERE accepted_by IS NULL AND rejected_by IS NULL AND session_id = ? )');
			$undoModObjects = new Updater($this->marie, $this->log, 'i',
			    'UPDATE objects SET moderation_flag_ct = moderation_flag_ct - 1
			      WHERE moderation_flag_ct > 0 AND moderation_status IS NULL AND object_id IN
			            ( SELECT object_id FROM moderations
			               WHERE accepted_by IS NULL AND rejected_by IS NULL AND session_id = ? )');
			$undoModPitches = new Updater($this->marie, $this->log, 'i',
			    'UPDATE pitches SET moderation_flag_ct = moderation_flag_ct - 1
			      WHERE moderation_flag_ct > 0 AND moderation_status IS NULL AND pitch_id IN
			            ( SELECT pitch_id FROM moderations
			               WHERE accepted_by IS NULL AND rejected_by IS NULL AND session_id = ? )');
			$purgeModerations = new Updater($this->marie, $this->log, 'ii',
			    'UPDATE moderations SET rejected_by = ?  -- maybe:  , pitch_id = NULL, subject_id = NULL, verb_id = NULL, object_id = NULL
			      WHERE accepted_by IS NULL AND rejected_by IS NULL AND session_id = ?');

			$this->marie->begin_transaction();
			$ret = $this->blockSession($sessionId, true) &&
			       $purgePitches->update($sessionId) && $purgeSubjects->update($sessionId, $sessionId) &&
			       $purgeVerbs->update($sessionId, $sessionId) && $purgeObjects->update($sessionId, $sessionId) &&
			       $undoModSubjects->update($sessionId) && $undoModVerbs->update($sessionId) &&
			       $undoModObjects->update($sessionId) && $undoModPitches->update($sessionId) &&
			       $purgeModerations->update($this->sessionId, $sessionId);
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
			return false;
		}
	}

	public function getSuspiciousSessions(int $ageInDays, bool $everybodyIsSuspicious): ?array
	{
		try
		{
			$findUsers = new Selector($this->marie, $this->log, 'iii', '
			    WITH sesns AS ( SELECT session_id, nickname, signature, when_last_used, when_last_reviewed
			                      FROM sessions
			                     WHERE datediff(now(), when_last_used) <= ?
			                       AND (blocked_by IS NULL OR ? > 0) ),
			         words AS ( SELECT session_id, sum(s.is_deleted + v.is_deleted + o.is_deleted) AS word_deletions,
			                           count(DISTINCT suggestion_id) AS ideas
			                      FROM sesns INNER JOIN
			                           suggestions g USING (session_id) INNER JOIN
			                           subjects s    USING (subject_id) INNER JOIN
			                           verbs v       USING (verb_id)    INNER JOIN
			                           objects o     USING (object_id) 
			                     WHERE when_suggested >= ifnull(when_last_reviewed, DATE \'1901-01-01\')
			                     GROUP BY session_id ),
			         ptchs AS ( SELECT session_id, sum(is_deleted) AS pitch_deletions, count(pitch_id) AS pitches
			                      FROM sesns INNER JOIN pitches USING (session_id)
			                     WHERE when_submitted >= ifnull(when_last_reviewed, DATE \'1901-01-01\')
			                     GROUP BY session_id ),
			         jects AS ( SELECT session_id, count(moderation_id) AS reject_count
			                      FROM sesns INNER JOIN moderations USING (session_id)
			                     WHERE rejected_by IS NOT NULL   -- use a separate rejection date column?
			                       AND when_submitted >= ifnull(when_last_reviewed, DATE \'1901-01-01\')
			                     GROUP BY session_id )
			    SELECT session_id, nickname, signature, when_last_used,
			           ifnull(word_deletions, 0) + ifnull(pitch_deletions, 0) AS deletions
			      FROM sesns LEFT JOIN
			           words USING (session_id) LEFT JOIN
			           ptchs USING (session_id) LEFT JOIN
			           jects USING (session_id)
			     WHERE (ideas IS NOT NULL OR pitches IS NOT NULL OR reject_count IS NOT NULL)
			       AND (ifnull(word_deletions, 0) + ifnull(pitch_deletions, 0) > 0
			            OR ifnull(reject_count, 0) > 0 OR ? > 0)
			     ORDER BY deletions DESC, when_last_used DESC');

			$results = [];
			$findUsers->select($ageInDays, $everybodyIsSuspicious, $everybodyIsSuspicious);
			do {
				$u = new SuspiciousSession();
				$gotten = $findUsers->getRow($u->sessionId, $u->nickname, $u->signature, $u->whenLastUsed, $u->deletions);
				if ($gotten)
					$results[] = $u;
			} while ($gotten);
			return $results;
		}
		catch (Throwable $ex)
		{
			$this->saveError($ex);
			return null;
		}
	}
}
?>
