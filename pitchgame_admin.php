<!DOCTYPE html>
<?php
// This page is for moderators and administrators.  Set it to be accessible only with a password.

require 'pitchdata_admin.php';

$pagestate = 0;			// 0 = outstanding moderation requests, 1 = user summary, 2 = user history, 3 = just blocked, 4 = confirm purge
$con = new PitchGameAdminConnection();		// defined in pitchdata_admin.php

$validationFailed = false;
$databaseFailed = !$con->isReady();

$resolved = 0;
$pitchesFlagged = 0;
$wordsFlagged = 0;
$suspiciousUserDays = 3;
$undine = false;
$purgery = false;
$moderationRequests = null;
$userSummary = null;
$suspiciousSessions = null;
$history = null;

function myIP()
{
	return $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
}

function enc(?string $str)
{
	return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5);
}

function englishNumber(int $n)
{
	$words = explode(' ', 'zero one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen twenty');
	return array_key_exists($n, $words) ? $words[$n] : (string) $n;
}


function describeBrowser(string $userAgent)
{
	// get_browser is not available on my PHP host, so let's recognize the main browsers only
	if (preg_match('/MSIE \d+/', $userAgent))
		$browser = 'IE (old)';
	else if (preg_match('/Trident\/7/', $userAgent))
		$browser = 'IE 11 or early Edge';
	else if (preg_match('/ Firefox\/\S+$/', $userAgent))
		$browser = 'Firefox';
	else if (preg_match('/ Safari\/\S$/', $userAgent))
		$browser = 'Safari';
	else if (preg_match('/ Edg\w*\/\S+$/', $userAgent))
		$browser = 'Edge';
	else if (preg_match('/ Chrome\/.* Safari/', $userAgent))
		$browser = 'Chrome';
	else
		$browser = 'unrecognized';
	return '<span class=browse title="' . enc($userAgent) . '">' . $browser . '</span>';
}

function warn(?int $number, string $description)
{
	if ($number <= 0)
		return "$number $description";
	else
		return "<span class=warn>$number $description</span>";
}

function countAndRange(int $count, ?string $earliest, ?string $latest)
{
	if ($count > 1)
		return "$count&ensp;from&ensp;$earliest&ensp;to&ensp;$latest";
	else
		return $count == 1 ? "$count&ensp;on&ensp;$earliest" : 'none';
}

function showFlagDelMod(int $shown, int $flagged, int $deleted, int $moderated)
{
	return $shown . ' views, ' . warn($flagged, 'flags') . ', ' . warn($deleted, 'deleted') . ', ' . warn($moderated, 'moderated');
}

function slink(int $sessionId)
{
	return "<a href='' id='session_$sessionId' class=slinky>user $sessionId</a>";
}

function attribution(int $id, string $when, int $sessionId, int $flagCount)
{
	return "$id, submitted $when by " . slink($sessionId) .
	       ($flagCount > 1 ? ", has $flagCount flags" : '');
}

function ject(?int $rejectedBy)
{
	return !$rejectedBy ? '' : " — <span class=warn>REJECTED by user $rejectedBy</span>";
}

function judgment($modStatus, $deleted, $prefix, $postfix)
{
	return !$modStatus && !$deleted ? '' :
	       "$prefix<span class=" . ($modStatus == 'Valid' ? 'antiwarn' : 'warn') . '>' .
	       ($deleted ? 'DELETED as ' . $modStatus : 'judged ' . $modStatus) . "</span>$postfix\n";
}

function histatus(HistoryPart $hp)
{
	return !is_numeric($hp->shownCount) ? judgment($hp->modStatus, $hp->deleted, '&ensp;(', ')') :
	       " — $hp->shownCount views, " . warn($hp->flagCount, 'flags') . judgment($hp->modStatus, $hp->deleted, ' — ', '');
}

function histword(string $part, ?string $word, HistoryPart $hp)
{
	return !$word ? '' :
	       "<br/>$part <span class='lit" . ($hp->deleted ? ' deleted' : '') . "'>“" . enc($word) . '”</span>' . histatus($hp);
}

function askWord(string $kind, int $id, string $when, int $sessionId, int $flagCount, int $dupes, string $word, int $moderationId)
{
	$rat = "type=radio name='modreq_$kind[0]_$moderationId'";
	$partOfSpeech = $kind == 'Verb' ? 'verb' : 'noun';
	return "<div class=uppergap>— $kind " . attribution($id, $when, $sessionId, $flagCount) .
	       ($dupes > 1 ? ", $dupes dupes" : '') .
	       "</div>\n<div class=qq>Is <span class=lit>“" . enc($word) .
	       "”</span> a valid $partOfSpeech?</div>\n<div>" .
	       "<label><input $rat value='Valid' /> Valid</label>" .
	       "<label><input $rat value='Dubious' /> Dubious</label>" .
	       "<label><input $rat value='Misspelled' /> Misspelled</label>" .
	       "<label><input $rat value='Non-$partOfSpeech' /> Not a $partOfSpeech, DELETE</label>" .
	       "<label><input $rat value='Gibberish' /> Gibberish, DELETE</label>" .
	       "<label><input $rat value='Spam' /> Spam, DELETE</label>" .
	       "<label><input $rat value='Evil' /> Hate or Crime, DELETE</label>" .
	       "<label><input $rat value='' checked /> (no answer)</label></div>\n";
}


// ---- process get/post args and do DB operations

if (!$databaseFailed)
{
	if (!isset($_COOKIE['pitchgame']) || !$con->getSessionByToken($_COOKIE['pitchgame'], myIP()))
	{
		$token = $con->makeSession(myIP(), $_SERVER['HTTP_USER_AGENT']);
		if ($token)
			setcookie('pitchgame', $token, time() + 366*86400);
		else
			$databaseFailed = true;
	}
	// else update ip_address and when_last_used?
}
else
	$con->lastError = "Database connection failed — $con->lastError";

if (isset($_GET['sessionId']) && !$databaseFailed)
{
	$sessionId = (int) $_GET['sessionId'];
	$pagestate = 1;
}
else if (isset($_POST['formtype']) && !$databaseFailed)		// extract form values, validate, and update
{
	if ($_POST['formtype'] == 'judgerequests')
	{
		$moderationRequests = unserialize($_POST['moderationrequests']);
		foreach ($moderationRequests as $rq)
		{
			$judgmentP = $_POST["modreq_P_$rq->moderationId"];
			$judgmentS = $_POST["modreq_S_$rq->moderationId"];
			$judgmentV = $_POST["modreq_V_$rq->moderationId"];
			$judgmentO = $_POST["modreq_O_$rq->moderationId"];
			if (!$con->saveJudgment($rq, $judgmentP, $judgmentS, $judgmentV, $judgmentO))
				$databaseFailed = true;
			else if ($judgmentP || $judgmentS || $judgmentV || $judgmentO)
				$resolved++;
		}
		$pagestate = 0;
		$moderationRequests = null;		// reload a fresh set
	}
	else if ($_POST['formtype'] == 'usualsuspects')
	{
		$suspiciousUserDays = (int) $_POST['daysold'] ?: 3;
		$pagestate = 0;
	}
	else if ($_POST['formtype'] == 'history')
	{
		$sessionId = (int) $_POST['sessionid'];
		$suspiciousUserDays = (int) $_POST['daysold'];
		$pagestate = 2;
	}
	else if ($_POST['formtype'] == 'judgeuser')
	{
		$sessionId = (int) $_POST['sessionid'];
		$suspiciousUserDays = (int) $_POST['daysold'];
		$disposal = $_POST['disposal'];
		if ($disposal == 'purge')
			$pagestate = 4;
		else
		{
			$history = unserialize($_POST['history']);
			foreach ($history as $h)
			{
				$pitcher = !!$_POST["attn_p$h->pitchId"];
				$suggester = !!$_POST["attn_g$h->suggestionId"];
				if ($pitcher && $h->p->live())
				{
					if ($con->ratePitch($h->pitchId, -1))
						++$pitchesFlagged;
					else
						$databaseFailed = true;
				}
				else if ($suggester)
				{
					if ($con->flagWordsForModeration($h, $h->s->live(), $h->v->live(), $h->o->live()))
						$wordsFlagged += (int) $h->s->live() + (int) $h->v->live() + (int) $h->o->live();
					else
						$databaseFailed = true;
				}
			}
			if ($disposal == 'block' && !$databaseFailed)
			{
				$databaseFailed = !$con->blockSession($sessionId, true);
				$pagestate = 3;
			}
			else
			{
				$databaseFailed = !$con->blockSession($sessionId, false);   // sets when_last_reviewed
				$pagestate = 0;
			}
		}
	}
	else if ($_POST['formtype'] == 'blocked')
	{
		$sessionId = (int) $_POST['sessionid'];
		$suspiciousUserDays = (int) $_POST['daysold'];
		$action = $_POST['block'];
		if ($action == 'undo')
			$databaseFailed = !($undine = $con->blockSession($sessionId, false));
		$pagestate = 0;
	}
	else if ($_POST['formtype'] == 'purging')
	{
		$sessionId = (int) $_POST['sessionid'];
		$suspiciousUserDays = (int) $_POST['daysold'];
		$confirm = $_POST['confirm'];
		if ($confirm == 'yes')
		{
			$purgery = $con->purgeSession($sessionId);
			$databaseFailed = !$purgery;
			$pagestate = 0;
		}
		else
			$pagestate = 2;
	}
}
// make sure each page view has its necessary data
if (!$databaseFailed)
{
	if (($pagestate == 1 || $pagestate == 2 || $pagestate == 4) && !$userSummary)
	{
		$userSummary = $con->sessionStats($sessionId);
		$databaseFailed = !$userSummary;
	}
	if ($pagestate == 0 && !$moderationRequests)
	{
		$moderationRequests = $con->getRecentModerationRequests();
		$databaseFailed = !is_array($moderationRequests);
	}
	else if ($pagestate == 2 && !$history && !$databaseFailed)
	{
		$history = $con->getSessionHistory($sessionId);
		$databaseFailed = !is_array($history);
	}
	if ($pagestate == 0 && !$databaseFailed && !count($moderationRequests))
	{
		$suspiciousSessions = $con->getSuspiciousSessions($suspiciousUserDays);
		$databaseFailed = !is_array($suspiciousSessions);
	}
}
?>
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="Author" content="Paul Kienitz">
	<meta name="Description" content="The Movie Pitch Game!  This is the page for moderators and administrators to manage flagged content.">

	<title>Admin for the Movie Pitch Game</title>
	<link rel="stylesheet" href="pitchgame.css">
	<script type="text/javascript" src="pitchgame.js"></script>
	<script type="text/javascript" src="/rockets/spare03.js"></script>
</head>



<body>

<div class="plop narrow" id=sessionStats>
<!-- div class=backer></div -->
<aside>
<div class=closer><span>×</span></div>
<div id=userSummarySpot></div>
</aside>
</div>


<main>
<h1>Movie Pitch Game Administration</h1>
<p>
	<a class=aftergap href='/pitchgame/'>Return to the game</a>
	<?php if ($pagestate != 0) { ?>
	<a id=backToList href='/pitchgame/pitchgame_admin.php'>Return to moderation list</a>
	<?php } ?>
<p>
	
<?php if ($databaseFailed) { ?>

	<h2 class=failure>Error... dang it</h2>
	<p>

	We’re sorry, but the Movie Pitch Game admin page has experienced an internal failure.&ensp;We
	will investigate the problem and try to prevent recurrences.

	</p>
	<div class=exceptional><?=nl2br(enc($con->lastError))?></div>

<?php } else if ($pagestate == 0 && $moderationRequests && count($moderationRequests)) { ?>

	<p>
	<?php if ($resolved) { ?>

	Thank you for handling <?=$resolved == 1 ? 'that moderation request' :
	   'those ' . englishNumber($resolved) . ' moderation requests' ?>.&ensp;Here are some more.

	<?php } else if ($undine) { ?>

	<p>The block has been undone.</p>

	<p>Here are the newest moderation requests.</p>

	<?php } else if ($pitchesFlagged || $wordsFlagged) { ?>

	<p>Thank you.&ensp;<?=$wordsFlagged?> words and <?=$pitchesFlagged?> pitches have been added to
	the moderation list.&ensp;Here are the newest items.</p>

	<?php } else  { ?>

	<p>Here are the most recent moderation requests — ideas marked as non-words, and pitches
	marked as spam.&ensp;Please check each one for validity.</p>

	<?php } ?>
	</p>
	<form method="POST" class=meta>
		<input type=hidden name=formtype id=formtype value='judgerequests' />
		<input type=hidden name=sessionid id=lastSessionId value='' />
		<input type=hidden name=moderationrequests value="<?=enc(serialize($moderationRequests))?>" />
	<?php foreach ($moderationRequests as $modreq) { ?>
		<div class=fields>
			<div>
				#<?=$modreq->moderationId?> »&ensp;At <?=$modreq->whenRequested?>,
				<?=slink($modreq->requestorSessionId)?>
				<!--?=$modreq->flagDupes > 2 ? '(and ' . englishNumber($modreq->flagDupes - 1) . ' others)' :
				  ($modreq->flagDupes > 1 ? '(and one other)' : '')?-->
				flagged this:
			</div>
		<?php if ($modreq->pitchId) { ?>
			<p>
				— Pitch <?=attribution($modreq->pitchId, $modreq->whenPitched, $modreq->pitchSessionId, $modreq->pitchFlagCount)?>:
			</p>
			<!-- div>
				Idea: “<?=enc($modreq->subject)?> <?=enc($modreq->verb)?> <?=enc($modreq->object)?>”
			</div -->
			<blockquote>
				<div>Title: <span class=lit>“<?=enc($modreq->title)?>”</span></div>
				<div>Pitch: <span class=lit>“<?=enc($modreq->pitch)?>”</div>
				<div>Signature: <?=$signature ? '<span class=lit>“' . enc($modreq->signature) . '”</span>' : '(none)'?></div>
			</blockquote>
			<div class=qq>Is that a valid pitch?</div>
			<div>
			<label><input type=radio name='modreq_P_<?=$modreq->moderationId?>' value='Valid' /> Valid</label>
			<label><input type=radio name='modreq_P_<?=$modreq->moderationId?>' value='Dubious' /> Dubious</label>
			<label><input type=radio name='modreq_P_<?=$modreq->moderationId?>' value='Gibberish' /> Gibberish, DELETE</label>
			<label><input type=radio name='modreq_P_<?=$modreq->moderationId?>' value='Spam' /> Spam, DELETE</label>
			<label><input type=radio name='modreq_P_<?=$modreq->moderationId?>' value='Evil' /> Hate or Crime, DELETE</label>
			<label><input type=radio name='modreq_P_<?=$modreq->moderationId?>' value='' checked /> (no answer)</label>
			</div>
		<?php } else {
			if ($modreq->subjectId)
				echo askWord('Subject noun', $modreq->subjectId, $modreq->whenSubject, $modreq->subjectSessionId,
				             $modreq->subjectFlagCount, $modreq->subjectDupes, $modreq->subject, $modreq->moderationId);
			if ($modreq->verbId)
				echo askWord('Verb', $modreq->verbId, $modreq->whenVerb, $modreq->verbSessionId,
				             $modreq->verbFlagCount, $modreq->verbDupes, $modreq->verb, $modreq->moderationId);
			if ($modreq->objectId)
				echo askWord('Object noun', $modreq->objectId, $modreq->whenObject, $modreq->objectSessionId,
				             $modreq->objectFlagCount, $modreq->objectDupes, $modreq->object, $modreq->moderationId);
		} ?>
		</div>
	<?php } ?>
		<p>
			<button>Pronounce your Judgments</button>
		</p>
	</form>

<?php } else if ($pagestate == 0) { ?>

	<?php if ($resolved) { ?>

	<p>Thank you for handling <?=$resolved == 1 ? 'that moderation request' :
	       'those ' . englishNumber($resolved) . ' moderation requests' ?>.&ensp;There
	   are no longer any outstanding requests.</p>
	
	<?php } else { ?>

	<p>No outstanding moderation requests found.&ensp;Thanks for checking.</p>

	<?php } ?>

	<p>So instead, here is a list of active users who have had one or more of
	their word or pitch submissions deleted.&ensp;You can review their histories
	and see if they need further intervention.</p>

	<form method="POST" class="meta direct">
		<input type=hidden name=formtype id=formtype value='usualsuspects' />
		<input type=hidden name=sessionid id=lastSessionId value='' />

		<p>Showing users active in the last
		<select name=daysold id=daysOldDropdown>
			<option value=3 <?=$suspiciousUserDays <= 5 ? 'selected' : ''?>>3</option>
			<option value=10 <?=$suspiciousUserDays > 5 && $suspiciousUserDays < 20 ? 'selected' : ''?>>10</option>
			<option value=30 <?=$suspiciousUserDays >= 20 && $suspiciousUserDays < 60 ? 'selected' : ''?>>30</option>
			<option value=100 <?=$suspiciousUserDays >= 60 ? 'selected' : ''?>>100</option>
		</select>
		days:</p>

		<?php foreach ($suspiciousSessions as $sus) { ?>
			<p>
				<?=$sus->deletions?> deletions, active <?=$sus->whenLastUsed?>: <?=slink($sus->sessionId)?>
				<?=$sus->signature ? '— “' . enc($sus->signature) . '”' : '' ?>
			</p>
		<?php } ?>
	</form>

<?php } else if (($pagestate == 1 || $pagestate == 2) && !$userSummary) { ?>

	<div id=userSummary>
		<p>User <?=enc($_GET['sessionId'])?> not found.</p>
	</div>

<?php } else if ($pagestate == 1 || $pagestate == 2) { ?>

	<?php if ($pagestate == 1) { ?>
	<form method="POST" action='pitchgame_admin.php'>
		<input type=hidden name=formtype id=formtype value='history' />
		<input type=hidden name=sessionid value='<?=$sessionId?>' />
	</form>
	<?php } ?>
	<div id=userSummary>
		<table class=userSummary>
			<tr><td>Session status:</td>
			    <td>#<?=$sessionId?> is <?=$userSummary->blockedBy ? "<span class=warn>BLOCKED by $userSummary->blockedBy</span>" : ($userSummary->isTest ? 'TESTER' : 'active')?>
			        — <?=$userSummary->signature ? 'signature is “<span class=lit>' . enc($userSummary->signature) . '”</span>' : 'no default signature'?></td>
			</tr>
			<tr><td>Connection:</td>
			    <td>browser <?=describeBrowser($userSummary->userAgent)?>, IP address <?=enc($userSummary->ipAddress)?></td>
			</tr>
			<tr><td>Ideas submitted:</td>
			    <td><?=countAndRange($userSummary->ideasCount, $userSummary->ideasEarliest, $userSummary->ideasLatest)?></td>
			</tr>
	<?php if ($userSummary->ideasCount) { ?>
			<tr><td>Usage of words:</td>
			    <td><?=showFlagDelMod($userSummary->wordsShownCount, $userSummary->wordsFlaggedCount, $userSummary->wordsDeletedCount, $userSummary->wordsModeratedCount)?></td>
			</tr>
	<?php } ?>
			<tr><td>Pitches written:</td>
			    <td><?=countAndRange($userSummary->pitchesCount, $userSummary->pitchesEarliest, $userSummary->pitchesLatest)?></td>
			</tr>
	<?php if ($userSummary->pitchesCount) { ?>
			<tr><td>Usage of pitches:</td>
				<td><?=showFlagDelMod($userSummary->pitchesShownCount, $userSummary->pitchesFlaggedCount, $userSummary->pitchesDeletedCount, $userSummary->pitchesModeratedCount)?></td>
			</tr>
	<?php } ?>
			<tr><td>Moderation requests:</td>
			    <td><?=countAndRange($userSummary->modRequestsCount, $userSummary->modRequestsEarliest, $userSummary->modRequestsLatest)?></td>
			</tr>
	<?php if ($userSummary->modRequestsCount) { ?>
			<tr><td>Moderation outcomes:</td>
			    <td><?=$userSummary->modRequestsAcceptedCount?> accepted, <?=$userSummary->modRequestsRejectedCount?> rejected.</td>
			</tr>
	<?php } ?>
		</table>
	<?php if ($pagestate == 1) { ?>
		<p>
			<a href='' id=historicize>View full submission history</a>
		</p>
	<?php } ?>
	</div>

	<?php if ($pagestate == 2) { ?>
	
	<h2>History of user <?=$sessionId?>’s ideas and pitches:</h2>
	<p>

	Here is everything they’ve submitted, from most recent to oldest.&ensp;If you
	see that the user is mainly making an honest effort, you can use the “Flag for
	further ttention” checkbox to flag the bad cases for moderation.&ensp;On the
	other hand, if there’s plenty of bad and not much good here, there’s no need
	to take that much trouble... in that case, there’s an option to completely
	purge everything the user has submitted.

	</p>
	<form method="POST" class=meta>
		<input type=hidden name=formtype id=formtype value='judgeuser' />
		<input type=hidden name=sessionid value='<?=$sessionId?>' />
		<input type=hidden name=daysold value='<?=$suspiciousUserDays?>' />
		<input type=hidden name=history value='<?=enc(serialize($history))?>' />
		<?php foreach ($history as $h) { ?>
		<blockquote class="pitch <?=$h->deleted() ? ' dark' : ''?><?=$h->rejectedBy ? ' malev' : ($h->acceptedBy ? ' benev' : '')?>">
			<?php if ($h->pitchId) { ?>
				<?php if ($h->moderationId) { ?>
				<div class=lowergap>
					Flagged this pitch <?=$h->whenPosted . judgment($h->p->modStatus, $h->p->deleted, ' (', ')') . ject($h->rejectedBy)?>
				</div>
				<?php } else { ?>
				<div class=lowergap>
					Written <?=$h->whenPosted?><?=histatus($h->p)?>
				</div>
				<?php } ?>
				<div <?=$h->p->deleted ? 'class=deleted' : ''?>>
					<div>Title: <span class=lit>“<?=enc($h->title)?>”</span></div>
					<div>Pitch: <span class=lit>“<?=enc($h->pitch)?>”</div>
					<div>Signature: <?=$signature ? '<span class=lit>“' . enc($h->signature) . '”</span>' : '(none)'?></div>
				</div>
				<?php if ($h->p->live()) { ?>
					<label class=uppergap><input type=checkbox name='attn_p<?=$h->pitchId?>' value=1 /> Flag for further attention</label>
				<?php } ?>
			<?php } else { ?>
				<?php if ($h->moderationId) { ?>
				<div>Flagged bad word <?=$h->whenPosted . ject($h->rejectedBy)?></div>
				<?php } else { ?>
				<div>Submitted <?=$h->whenPosted?></div>
				<?php } ?>
				<div>
					<?=histword('Subject', $h->subject, $h->s)?>
					<?=histword('Verb',    $h->verb,    $h->v)?>
					<?=histword('Object',  $h->object,  $h->o)?>
				</div>
				<?php if ($h->s->live() || $h->v->live() || $h->o->live()) { ?>
					<div class=uppergap>
						<label><input type=checkbox name='attn_g<?=$h->suggestionId?>' value=1 /> Flag for further attention</label>
					</div>
				<?php } ?>
			<?php } ?>
		</blockquote>
		<?php } ?>

	<p>

	Besides submitting any flag-for-attention checkboxes you may have set, these
	buttons allow you to decide the fate of the user who wrote the material
	above.&ensp;(If you choose “Purge Everything”, the checkboxes won’t matter.)

	</p><p>

	<button name=disposal value=permit>Let them keep playing</button><br/>
	<button name=disposal value=block>Block this user (but do not purge)</button><br/>
	<button name=disposal value=purge>Block them and Purge Everything</button>

	</p>
	</form>
	<?php } ?>

<?php } else if ($pagestate == 3) { ?>

	<p>Thank you.&ensp;<?=$wordsFlagged?> words and <?=$pitchesFlagged?> pitches have been added to
	the moderation list.&ensp;User <?=$sessionId?> is now blocked.</p>

	<form method=POST>
		<input type=hidden name=formtype value='blocked' />
		<input type=hidden name=sessionid value='<?=$sessionId?>' />
		<button name=block value=undo>Woops, undo the block!</button><br/>
		<button name=block value=okay>OK, return to moderation list</button>
	</form>

<?php } else if ($pagestate == 4) { ?>

	<p>Are you sure you want to purge everything submitted by user <?=$sessionId?>?&ensp;If you
	confirm, all of the words and pitches listed on the previous page will be deleted, and
	they will also be blocked from further participation.</p>

	<p>The purge will delete <?=$userSummary->pitchesCount - $userSummary->pitchesDeletedCount?>
	pitches (<?=$userSummary->pitchesDeletedCount?> are already deleted).&ensp;It will delete up to
	<?=3 * $userSummary->ideasCount - $userSummary->wordsDeletedCount?> nouns and verbs, omitting
	any also used by other players (<?=$userSummary->wordsDeletedCount?> are already deleted).</p>

	<form method=POST>
		<input type=hidden name=formtype value='purging' />
		<input type=hidden name=sessionid value='<?=$sessionId?>' />
		<button name=confirm value=yes>Yes, DELETE IT ALL</button><br/>
		<button name=confirm value=no>No, do not delete or block</button>
	</false>

<?php } ?>
</main>

<p style='margin-top: 2em'>
	<a href='' id=showLog class=meta>show log</a>
</p>
<p id=theLog class=exceptional style='display: none'>
	<?=nl2br(enc($con->getLog() ?: 'no log entries recorded'))?>
</p>

</body>
</html>
