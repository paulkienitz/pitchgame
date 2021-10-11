<!DOCTYPE html>
<?php
	// This page is for moderators and administrators.  Set it to be accessible only with a password.

	// TODO: start with master list of moderation stats and links: requests, iffy users, ?
	//       implement user history review with block and delete options

	require 'pitchdata.php';

	$pagestate = 0;			// 0 = FOR NOW, list of outstanding moderation requests, 1 = user summary
	$con = new PitchGameConnection();		// defined in pitchdata.php

	$validationFailed = false;
	$databaseFailed = !$con->isReady();

	$resolved = 0;

	function myIP()
	{
		return $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
	}

	function enc(string $str)
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
		else if (preg_match('/ Chrome\/.* Safari/', $userAgent))
			$browser = 'Chrome';
		else if (preg_match('/ Firefox\/\S+$/', $userAgent))
			$browser = 'Firefox';
		else if (preg_match('/ Safari\/\S$/', $userAgent))
			$browser = 'Safari';
		else if (preg_match('/ Edg\w*\/\S+$/', $userAgent))
			$browser = 'Edge';
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
		return $shown . ' shown, ' . warn($flagged, 'flagged') . ', ' . warn($deleted, 'deleted') . ', ' . warn($moderated, 'moderated');
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

	function askWord(string $kind, int $id, string $when, int $sessionId, int $flagCount, int $dupes, string $word, int $moderationId)
	{
		$rat = "type=radio name='modreq_$kind[0]_$moderationId'";
		$partOfSpeech = $kind == 'Verb' ? 'verb' : 'noun';
		return "<div>— $kind " . attribution($id, $when, $sessionId, $flagCount) .
		       ($dupes > 1 ? ", $dupes dupes" : '') .
		       "</div>\n<div class=qq>Is <span class=lit>“" . enc($word) .
		       "”</span> a valid $partOfSpeech?</div>\n<div>" .
		       "<label><input $rat value='Valid' /> Valid</label>" .
		       "<label><input $rat value='Spelling' /> Misspelled</label>" .
		       "<label><input $rat value='Non-$partOfSpeech' /> Not a $partOfSpeech, DELETE</label>" .
		       "<label><input $rat value='Gibberish' /> Gibberish, DELETE</label>" .
		       "<label><input $rat value='' /> (no answer)</label></div>\n";
	}


	// ---- process get/post args and do DB operations

	if (!$databaseFailed)
	{
		if (!isset($_COOKIE['pitchgame']) || !$con->getSessionByToken($_COOKIE['pitchgame']))
		{
			$token = $con->makeSession(myIP(), $_SERVER['HTTP_USER_AGENT']);
			if ($token)
				setcookie('pitchgame', $token);
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
		$userSummary = $con->sessionStats($sessionId);
		$pagestate = 1;
	}
	else if (isset($_POST['formtype']) && !$databaseFailed)		// extract form values, validate, and update
	{
		if ($_POST['formtype'] == 'judgerequests')
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
		if (!$databaseFailed)
		{
			// refresh the list
			$moderationRequests = $con->getRecentModerationRequests();
			$pagestate = 0;
		}
	}
	else
	{
		$moderationRequests = $con->getRecentModerationRequests();
		$pagestate = 0;
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
	
<?php if ($databaseFailed) { ?>

	<h2 class=failure>Error... dang it</h2>
	<p>

	We’re sorry, but the Movie Pitch Game admin page has experienced an internal failure.&ensp;We
	will investigate the problem and try to prevent recurrences.

	</p>
	<div class=exceptional><?=nl2br(enc($con->lastError ?: $con->getLog()))?></div>

<?php } else if ($pagestate == 0 && $moderationRequests && count($moderationRequests)) { ?>

	<p>
	<?php if ($resolved) { ?>

	Thank you for handling <?=$resolved == 1 ? 'that moderation request' :
	   'those ' . englishNumber($resolved) . ' moderation requests' ?>.&ensp;Here are some more.

	<?php } else  { ?>

	Here are the ten most recent moderation requests — ideas marked as non-words, and pitches
	marked as spam.&ensp;Please check each one for validity.

	<?php } ?>
	</p>
	<form method="POST" class=meta>
		<input type=hidden name=formtype value="judgerequests" />
		<input type=hidden name=moderationrequests value="<?=enc(serialize($moderationRequests))?>" />
	<?php foreach ($moderationRequests as $modreq) { ?>
		<div class=fields>
			<p>(#<?=$modreq->moderationId?>) At <?=$modreq->whenRequested?>,
			   <?=slink($modreq->requestorSessionId)?> flagged this:</p>
		<?php if ($modreq->pitchId) { ?>
			<div>
				— Pitch <?=attribution($modreq->pitchId, $modreq->whenPitched, $modreq->pitchSessionId, $modreq->pitchFlagCount)?>:
			</div>
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
			<label><input type=radio name='modreq_P_<?=$modreq->moderationId?>' value='Spam' /> Spam, DELETE</label>
			<label><input type=radio name='modreq_P_<?=$modreq->moderationId?>' value='Gibberish' /> Gibberish, DELETE</label>
			<label><input type=radio name='modreq_P_<?=$modreq->moderationId?>' value='' /> (no answer)</label>
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
	<!-- div class=exceptional><?=nl2br(enc($con->getLog()))?></div -->

<?php } else if ($pagestate == 0) { ?>

	<?php if ($resolved) { ?>

	<p>Thank you for handling <?=$resolved == 1 ? 'that moderation request' :
	       'those ' . englishNumber($resolved) . ' moderation requests' ?>.&ensp;There
	   are no longer any outstanding requests.</p>
	
	<?php } else { ?>

	<p>No outstanding moderation requests found.&ensp;Thanks for checking.</p>
	
	<?php } ?>
	<!-- div class=exceptional><?=nl2br(enc($con->getLog()))?></div -->

<?php } else if ($pagestate == 1 && !$userSummary) { ?>

	<div id=userSummary>
		<p>User <?=enc($_GET['sessionId'])?> not found.</p>
	</div>

<?php } else if ($pagestate == 1) { ?>

	<div id=userSummary>
		<table class=userSummary>
			<tr><td>Session <?=$sessionId?> status:</td>
			    <td><?=$userSummary->isBlocked ? '<span class=warn>BLOCKED</span>' : ($userSummary->isTest ? 'TEST' : 'active')?>,
			        <?=$userSummary->signature ? '“<span class=lit>' . enc($userSummary->signature) . '”</span>' : 'no default signature'?></td>
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
		</table
	</div>

<?php } ?>
</main>

</body>
</html>
