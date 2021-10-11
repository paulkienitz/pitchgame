<!DOCTYPE html>
<?php
	// This page contains all views for the gameplay loop seen by regular players.

	require 'pitchdata.php';

	$pagestate = 0;			// 0 = initial prompt for words, 1 = prompt for pitch, 2 = rate pitches, 3 = old faves, 4/5 = thanks and restart, 6 = mark bad words
	$con = new PitchGameConnection();		// defined in pitchdata.php
	$submissionId = null;

	$validationFailed = false;
	$databaseFailed = !$con->isReady();
	$initialSubject = '';
	$initialVerb = '';
	$initialObject = '';
	$idea = '';
	$ideaWordCount;
	$challengeSummary = '';
	$title = '';
	$pitch = '';
	$signature = '';

	$challenge = null;				// will contain three words and their IDs, structure defined in pitchdata.php
	$pitchesToReview = [];			// array of Pitch structures defined in pitchdata.php

	function myIP()
	{
		return $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
	}

	function enc(string $str)
	{
		return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5);
	}

	function despace(string $str)
	{
		return trim(preg_replace('/\s+/', ' ', $str));
	}

	function englishNumber(int $n)
	{
		$words = explode(' ', 'zero one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen twenty');
		return array_key_exists($n, $words) ? $words[$n] : (string) $n;
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
	}
	else
		$con->lastError = "Database connection failed - $con->lastError";

	if (isset($_POST['formtype']) && !$databaseFailed)		// extract form values, validate, and update
	{
		if ($_POST['formtype'] == 'initialwords')			// pagestate 0, 4, or 5 form submitted
		{
			$initialSubject = despace($_POST['subject']);
			$initialVerb    = despace($_POST['verb']);
			$initialObject  = despace($_POST['object']);
			$validationFailed = !$initialSubject || !$initialVerb || !$initialObject;
			if (!$validationFailed)
			{
				if ($con->addWords($initialSubject, $initialVerb, $initialObject))
					$challenge = $con->getChallenge();
				$databaseFailed = !$challenge;
				$idea = despace("$initialSubject $initialVerb $initialObject");		// for display
				$ideaWordCount = substr_count($idea, ' ') + 1;
				$challengeSummary = despace("$challenge->subject $challenge->verb $challenge->object");
			}
			$pagestate = $validationFailed ? 0 : 1;
		}
		else if ($_POST['formtype'] == 'pitch')				// pagestate 1 form submitted
		{
			$idea          = despace($_POST['idea']);
			$challenge     = unserialize($_POST['challenge']);
			$title         = despace($_POST['title']);
			$pitch         = trim($_POST['pitch']);
			$signature     = trim($_POST['signature']);
			$ideaWordCount    = substr_count($idea, ' ') + 1;
			$challengeSummary = despace("$challenge->subject $challenge->verb $challenge->object");
			$validationFailed = !$title || !$pitch;
			if (!$validationFailed)
			{
				$databaseFailed = !$con->addPitch($challenge, $title, $pitch, $signature);
				if (!$databaseFailed)
				{
					$pitchesToReview = $con->getPitchesToReview();
					if ($pitchesToReview && count($pitchesToReview))
						$pagestate = 2;
					else
					{
						$oldFavoritePitches = $con->getOldFavoritePitches();
						if ($oldFavoritePitches && count($oldFavoritePitches))
							$pagestate = 3;
						else
							$databaseFailed = true;
					}
				}
			}
			else
				$pagestate = 1;
		}
		else if ($_POST['formtype'] == 'moderate')			// pagestate 1 bad word link clicked
		{
			$idea          = despace($_POST['idea']);
			$challenge     = unserialize($_POST['challenge']);
			$title         = despace($_POST['title']);
			$pitch         = trim($_POST['pitch']);
			$signature     = trim($_POST['signature']);
			$pagestate = 6;
		}
		else if ($_POST['formtype'] == 'review')			// pagestate 2 form submitted
		{
			$pitchesToReview = unserialize($_POST['pitchesToReview']);
			$anyRated = false;
			foreach ($pitchesToReview as $pitcher)
			{
				$rating = trim($_POST['newrating_' . $pitcher->pitchId]);
				if ($rating)
					if ($con->ratePitch($pitcher->pitchId, $rating))
						$anyRated = true;
					else
						$databaseFailed = true;
			}
			if (!$databaseFailed)
				$pagestate = $anyRated ? 5 : 4;
		}
		else if ($_POST['formtype'] == 'favorites')			// pagestate 3 form submitted
		{
			$pagestate = 4;
		}
		else if ($_POST['formtype'] == 'reportbad')			// pagestate 6 form submitted
		{
			$idea          = despace($_POST['idea']);
			$challenge     = unserialize($_POST['challenge']);
			$title         = despace($_POST['title']);
			$pitch         = trim($_POST['pitch']);
			$signature     = trim($_POST['signature']);
			$badsubject    = !!$_POST['badsubject'];
			$badverb       = !!$_POST['badverb'];
			$badobject     = !!$_POST['badobject'];
			$ideaWordCount = substr_count($idea, ' ') + 1;
			if ($badsubject || $badverb || $badobject)
			{
				$tc = $con->flagWordsForModeration($challenge, $badsubject, $badverb, $badobject);
				if (!$tc)
					$databaseFailed = true;
				else
					$challenge = $tc;
			}
			$challengeSummary = despace("$challenge->subject $challenge->verb $challenge->object");
			$pagestate = 1;
		}
	}
?>
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="Author" content="Paul Kienitz">
	<meta name="Description" content="The Movie Pitch Game!  Supply three words, get three words back, and write a movie pitch fitting them.">
	<meta name="KeyWords" content="movies, film, cinema, hollywood, pitch, sell, ideas, game, entertainment, creative, KO Rob, KO Picture Show, kopictureshow.com">

	<title>The Movie Pitch Game!</title>
	<link rel="stylesheet" href="pitchgame.css">
	<script type="text/javascript" src="pitchgame.js"></script>
</head>



<body>

<div class=plop id=howtoplay>
<div class=backer></div>
<aside>
<div class=closer><span>×</span></div>

<?php require 'pitchhints.html-content'; ?>

</aside>
</div>


<main>
<h1 class=title>The Movie Pitch Game!</h1>
<h3 class=subtitle>invented by <a href='http://kopictureshow.com'>KO Rob</a></h3>
	
<?php if ($databaseFailed) { ?>

	<h2 class=failure>Error... dang it</h2>
	<p>

	We’re sorry, but the Movie Pitch Game has experienced an internal failure.&ensp;We
	will investigate the problem and try to prevent recurrences.

	</p>
	<div class=exceptional><?=nl2br(enc($con->lastError ?: $con->getLog()))?></div>

<?php } else if ($pagestate == 0 || $pagestate == 4 || $pagestate == 5) { ?>

	<p>

	<?php if ($pagestate == 0) { ?>

	This is a game in which you are given a three word story idea, in the form of
	a short sentence with a verb in the middle, like “man bites dog” or “Martians
	invade Belgium”.&ensp;Your job is to pitch a movie based on that idea — that
	is, to describe an unmade film in a paragraph or two, and make it sound like
	something people would want to see.&ensp;For further explanation,
	<a href='' id=showhints>click here</a>.

	</p><p>

	But before you get your three words, you must supply three words for other
	people to use.&ensp;Please give us a noun, a verb, and another noun:

	<?php } else { ?>

	<i>Thank you for <?=($pagestate == 5 ? "rating" : "reading")?> some of the
	submissions of your fellow players!</i>&ensp;How about playing another round?

	<?php } ?>

	</p>
	<form method="POST" class=fields>
		<input type=hidden name=formtype value="initialwords" />
		<div class=narrowgowides>
			<label for=subject>Noun:</label>
			<input type=text id=subject name=subject value='<?=enc($initialSubject)?>' maxlength=50 tabindex=1 />
			<label for=verb>Verb:</label>
			<input type=text id=verb name=verb value='<?=enc($initialVerb)?>' maxlength=50 tabindex=2 />
			<label for=object>Noun:</label>
			<input type=text id=object name=object value='<?=enc($initialObject)?>' maxlength=50 tabindex=3 />
		</div>
	<?php if ($validationFailed) { ?>
		<div class=validation>
			All three values are required if you want to play!
		</div>
	<?php } ?>
		<p>
			<button tabindex=4>Submit Words so I can Make My Pitch</button>
		</p>
	</form>
	<!-- div class=exceptional><?=nl2br(enc($con->getLog()))?></div -->

<?php } else if ($pagestate == 1) { ?>

	<p>

	Thank you.&ensp;Your three word
	<?=$ideaWordCount <= 3 ? '' : '— er, ' . englishNumber($ideaWordCount) . ' word? —'?>
	idea, “<?=enc($idea)?>”, will soon be inspiring
	others to come up with creative movie pitches.

	</p><p>

	And now it’s your turn!&ensp;Your story idea is:

	</p>
	<h2 class=challenge>
	<?=enc($challengeSummary)?>
	</h2>
	<div class=modery>(<a href='' id=moderato>click here</a> if one or more words are invalid)</div>

	<form method="POST" id=pitchform class=fields>
		<input type=hidden name=formtype id=formtype value='pitch' />
		<input type=hidden name=idea value='<?=enc($idea)?>' />
		<input type=hidden name=challenge value='<?=enc(serialize($challenge))?>' />
		<div class=chungus>
			<label for=title>Movie Title:</label>
			<input type=text id=title name=title class=chungus value='<?=enc($title)?>' maxlength=100 tabindex=1 />
			<div>
			<label for=pitch>Pitch! :</label>
			<textarea id=pitch name=pitch maxlength=2000 tabindex=2><?=enc($pitch)?></textarea>
			</div><div style='position: relative'>
			<label class=optionality>(optional)</label>
			<label for=signature>Signature:</label>
			<input type=text id=signature name=signature value='<?=enc($signature)?>' maxlength=100 tabindex=3 />
			</div>
		</div>
	<?php if ($validationFailed) { ?>
		<div class=validation style='margin-top: 0.6em'>
			Both Movie Title and Pitch text are required!
		</div>
	<?php } ?>
		<p>
			<button tabindex=4>Submit My Pitch!</button>
		</p>
	</form>
	<!-- div class=exceptional><?=nl2br(enc($con->getLog()))?></div -->

<?php } else if ($pagestate == 2) { ?>

	<p>

	Thank you.&ensp;Here are some other folks’ pitches for you to read.&ensp;Enjoy, and if
	you want, leave a star rating for any or all of the pitches below.

	</p>
	<form method="POST">
		<input type=hidden name=formtype value='review' />
		<input type=hidden name=pitchesToReview value='<?=enc(serialize($pitchesToReview))?>' />
		<button>Submit My Ratings and Play Another Round</button>

	<?php foreach ($pitchesToReview as $pitcher) { ?>
		<blockquote class=pitch>
			<div>
				<i>from the idea “<?=enc($pitcher->subject)?>
			    <?=enc($pitcher->verb)?> <?=enc($pitcher->object)?>”:</i>
			</div>
			<h3><?=enc($pitcher->title)?></h3>
			<p style='white-space: pre-wrap'><?=enc($pitcher->pitch)?></p>
			<?php if ($pitcher->signature) echo "<div>&mdash; $pitcher->signature</div>\n"; ?>
			<div class=stars>
				<input type=hidden id='newrating_<?=$pitcher->pitchId?>' name='newrating_<?=$pitcher->pitchId?>' value=''></input>
				Your rating: 
				<a id='star_1_<?=$pitcher->pitchId?>' class=star title='1 star'>&#9734;</a>
				<a id='star_2_<?=$pitcher->pitchId?>' class=star title='2 stars'>&#9734;</a>
				<a id='star_3_<?=$pitcher->pitchId?>' class=star title='3 stars'>&#9734;</a>
				<a id='star_4_<?=$pitcher->pitchId?>' class=star title='4 stars'>&#9734;</a>
				<span id='spam_<?=$pitcher->pitchId?>' class=spam><a>Mark as spam</a></span>
				<span id='clear_<?=$pitcher->pitchId?>' class=spam><a>Undo</a></span>
			</div>
		</blockquote>
	<?php } ?>

		<button>Submit My Ratings and Play Another Round</button>
	</form>
	<!-- div class=exceptional><?=nl2br(enc($con->getLog()))?></div -->

<?php } else if ($pagestate == 3) { ?>

	<p>

	Thank you.&ensp;Unfortunately we have no pitches to show you for rating at this
	time.&ensp;Apparently you have been very diligent about rating everyone else’s
	pitches.&ensp;Instead, here are a few of your old favorites.

	</p>
	<form method="POST">
		<input type=hidden name=formtype value='favorites' />
		<button>Play Another Round</button>

	<?php foreach ($oldFavoritePitches as $pitcher) { ?>
		<blockquote class=pitch>
			<div>
				<i>from the idea “<?=enc($pitcher->subject)?>
			    <?=enc($pitcher->verb)?> <?=enc($pitcher->object)?>”:</i>
			</div>
			<h3>
				<?=enc($pitcher->title)?>
				<span class=star title='<?=$pitcher->yourRating?> stars'><?=str_repeat('&#9733;', $pitcher->yourRating)?></span>
			</h3>
			<p style='white-space: pre-wrap'><?=enc($pitcher->pitch)?></p>
			<?php if ($pitcher->signature) echo "<div>&mdash; $pitcher->signature</div>\n"; ?>
		</blockquote>
	<?php } ?>

		<button>Play Another Round</button>

	</form>
	<!-- div class=exceptional><?=nl2br(enc($con->getLog()))?></div -->

<?php } else if ($pagestate == 6) { ?>

	<p>

	Please let us know of any words which are unusable.&ensp;A moderator
	will check each report and delete the invalid words.&ensp;Meanwhile,
	you will return to the pitch writing page with a replacement word
	for each one you check off.

	</p><p>

	You should mark a word invalid if it’s definitely not the correct part
	of speech (“of”, “actually”, “tall”), if it’s gibberish (“Ggggggg”, “asdfasdf”),
	if it’s spam (“http://lose-weight-fast.zz.xx”), if it’s not English and
	wouldn’t be recognized by English speakers (“Mantergeistmännlichkeit”,
	“新代载人飞船”), or if it’s hate propaganda or promotes crime.

	</p><p>

	You should <i>not</i> mark words invalid because the sentence it makes is
	grammatically awkward due to mismatched plurals or tenses (“ichthyosaurs flies
	over pessimism”), because it has adult language (“Ben Franklin shits on your shoe”),
	or because you would really like an easier idea to pitch.&ensp;The inconvenience of
	trying to make creative sense of a jumbled idea is a core part of the
	game!&ensp;Abuse of this reporting feature will be monitored.
	
	</p><p>

	So with that in mind, please mark the words that are invalid, if any:

	</p>
	<form method="POST" id=pitchform class=fields>
		<input type=hidden name=formtype id=formtype value='reportbad' />
		<input type=hidden name=idea value='<?=enc($idea)?>' />
		<input type=hidden name=challenge value='<?=enc(serialize($challenge))?>' />
		<input type=hidden name=title value='<?=enc($title)?>' />
		<input type=hidden name=pitch value='<?=enc($pitch)?>' />
		<input type=hidden name=signature value='<?=enc($signature)?>' />
		<p>
		<label><input type=checkbox name=badsubject id=badSubject/> “<?=enc($challenge->subject)?>” is not a noun</label>
		</p><p>
		<label><input type=checkbox name=badverb id=badVerb/> “<?=enc($challenge->verb)?>” is not a verb</label>
		</p><p>
		<label><input type=checkbox name=badobject id=badObject/> “<?=enc($challenge->object)?>” is not a noun</label>
		</p><p>
		<button>Return to my Pitch</button>
		</p>
	</form>
	<!-- div class=exceptional><?=nl2br(enc($con->getLog()))?></div -->

<?php } ?>
</main>

</body>
</html>
