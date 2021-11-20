<!DOCTYPE html>
<?php
// This page contains all views for the gameplay loop seen by regular players.

// TODO: 
//
// BUGS: "with pits" queries for reviews or faves are intermittently slow, but still test as fast in phpmyadmin
//
// TEST: 
//
// IDEA: banner text curved cinemascope style via svg?
//       can dupe word validations be more friendly to back and refresh buttons?  do they need to be?
//       support sso identity, and use for admin... or simple password if that's too hard? or local pwd as max security?
//       team play!  need new session handling, challenge, and review queries...
//       real-time team play needs status display with push updates... chat window?  scheduled future invite?
//       delayed team play needs email and/or text notification... return visit must go to correct phase
//       word mod req page: examples easier to read with bullet list?
//       session history: add field for last reviewed by, null for new records?
//       history of own pitches? (incentive for login)
//       ...maybe a link to see other pitches by the same author?  only if signature used?
//       session history should search for IP matches
//       view history of accept, reject, ban, and bulk delete by other admins? super-admin page for this?
//       add more color?

require 'pitchdata.php';
require 'common.php';
require 'pitch-configure.php';

define('ASK3_COLD', 0);
define('PITCH',     1);
define('REVIEW',    2);
define('FAVES',     3);
define('ASK3_WARM', 4);
define('ASK3_RVWD', 5);
define('BADWORD',   6);
define('ASKNAME',   7);
define('ASK3_NAMD', 8);

$pagestate = ASK3_COLD;		// one of the constants above

$con = new PitchGameConnection();		// defined in pitchdata.php
$submissionId = null;

$validationFailed = false;
$databaseFailed = !$con->isReady();
$team = false;
$initialSubject = '';
$initialVerb = '';
$initialObject = '';
$idea = '';
$ideaWordCount = 0;
$challengeSummary = '';
$title = '';
$pitch = '';
$signature = '';

$dupeWords = null;              // structure defined in pitchdata.php, null if valid
$challenge = null;				// will contain three words and their IDs, structure defined in pitchdata.php
$pitchesToReview = [];			// array of Pitch structures defined in pitchdata.php


// ---- process get/post args and do DB operations

if (!$databaseFailed)
{
	$databaseFailed = !connectToSession($con);
	if (!$databaseFailed && SUPPORT_TEAMS && isset($_GET['team']))
		$team = $con->joinTeam($_GET['team']);
}
else
	$con->lastError = "Database connection failed - $con->lastError";

if (isset($_POST['g-recaptcha-response']))
{
	$captchaCheck = ['secret'   => CAPTCHA_SECRET,
	                 'remoteip' => myIP(),
	                 'response' => $_POST['g-recaptcha-response']];
	$rawResponse = doPost('https://www.google.com/recaptcha/api/siteverify', $captchaCheck);
	$captchaResponse = json_decode($rawResponse, false, 3);
	if (isset($captchaResponse->success) && $captchaResponse->success)
		$con->captchaPass();
	else
		$con->captchaFail($rawResponse, $_POST['g-recaptcha-response'], curl_getinfo($curly, CURLINFO_RESPONSE_CODE));
}

if (isset($_POST['formtype']) && !$databaseFailed)		// extract form values, validate, and update
{
	if ($_POST['formtype'] == 'initialwords')			// ASK3_* form submitted
	{
		$seed           = (int) $_POST['seed'];
		$variant        = (int) $_POST['variant'];
		$initialSubject = despace($_POST['subject']);
		$initialVerb    = despace($_POST['verb']);
		$initialObject  = despace($_POST['object']);
		$validationFailed = !$initialSubject || !$initialVerb || !$initialObject;
		if (!$validationFailed && !$con->needsCaptcha)
		{
			$dupeWords = $con->validateWords($initialSubject, $initialVerb, $initialObject);
			if (!$dupeWords)
			{
				if ($con->addWords($initialSubject, $initialVerb, $initialObject))  // no external difference for team play
					$challenge = $con->getChallenge($seed);
				$databaseFailed = !$challenge;
				$idea = despace("$initialSubject $initialVerb $initialObject");		// for display
				$ideaWordCount = substr_count($idea, ' ') + 1;
				$challengeSummary = despace("$challenge->subject $challenge->verb $challenge->object");
				$signature = trim($con->defaultSignature ?? $con->nickname);   // note that we fall back on null but not on blank
			}
		}
		$pagestate = $validationFailed || $dupeWords || $con->needsCaptcha ? $variant : PITCH;
	}
	else if ($_POST['formtype'] == 'pitch')				// PITCH form submitted
	{
		$idea             = despace($_POST['idea']);
		$challenge        = unserialize($_POST['challenge']);
		$seed             = (int) $_POST['seed'];
		$title            = despace($_POST['title']);
		$pitch            = trim($_POST['pitch']);
		$signature        = trim($_POST['signature']);
		$setDefaultSig    = !!$_POST['defsig'];
		$ideaWordCount    = substr_count($idea, ' ') + 1;
		$challengeSummary = despace("$challenge->subject $challenge->verb $challenge->object");
		$validationFailed = (!$title || !$pitch) && $seed;
		if (!$seed)           // re-randomize
		{
			$seed = rand(1000000, 2000000000);
			$challenge = $con->getChallenge($seed);
			$challengeSummary = despace("$challenge->subject $challenge->verb $challenge->object");
			$pagestate = PITCH;
		}
		else if (!$validationFailed)
		{
			$databaseFailed = !$con->addPitch($challenge, $title, $pitch, $signature, $setDefaultSig);
			if (!$databaseFailed)
			{
				$pitchesToReview = $con->getPitchesToReview($seed);
				if ($pitchesToReview && count($pitchesToReview))
					$pagestate = REVIEW;
				else
				{
					$oldFavoritePitches = $con->getOldFavoritePitches($seed);
					if ($oldFavoritePitches && count($oldFavoritePitches))
						$pagestate = FAVES;
					else
						$databaseFailed = true;
				}
			}
		}
		else
			$pagestate = PITCH;
	}
	else if ($_POST['formtype'] == 'moderate')			// PITCH bad word link clicked
	{
		$idea      = despace($_POST['idea']);
		$challenge = unserialize($_POST['challenge']);
		$title     = despace($_POST['title']);
		$pitch     = trim($_POST['pitch']);
		$signature = trim($_POST['signature']);
		$seed      = (int) $_POST['seed'];
		$pagestate = BADWORD;
	}
	else if ($_POST['formtype'] == 'review')			// REVIEW form submitted
	{
		$seed = (int) $_POST['seed'];
		if (!$seed)       // re-randomize
		{
			$seed = rand(1000000, 2000000000);
			$pitchesToReview = $con->getPitchesToReview($seed);
			$pagestate = REVIEW;
		}
		else
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
				$pagestate = $anyRated ? ASK3_RVWD : ASK3_WARM;
		}
	}
	else if ($_POST['formtype'] == 'favorites')			// FAVES form submitted
	{
		$pagestate = ASK3_WARM;
	}
	else if ($_POST['formtype'] == 'reportbad')			// BADWORD form submitted
	{
		$idea          = despace($_POST['idea']);
		$challenge     = unserialize($_POST['challenge']);
		$title         = despace($_POST['title']);
		$pitch         = trim($_POST['pitch']);
		$signature     = trim($_POST['signature']);
		$seed          = (int) $_POST['seed'];
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
		$pagestate = PITCH;
	}
	else if ($_POST['formtype'] == 'name')				// GETNAME firm submitted
	{
		$nickname = trim($_POST['name']);
		$validationFailed = !$nickname;
		if (!$validationFailed)
			$databaseFailed = !$con->setNickname($nickname);
		$pagestate = $validationFailed ? ASKNAME : ASK3_NAMD;
	}
}
if (($team || REQUIRE_NAME) && !$con->nickname)
	$pagestate = ASKNAME;
?>
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="Author" content="Paul Kienitz">
	<meta name="Description" content="The Movie Pitch Game!  Supply three words, get three words back, and write a movie pitch fitting them.">
	<meta name="KeyWords" content="movies, film, cinema, hollywood, pitch, sell, ideas, game, entertainment, creative, KO Rob, KO Picture Show, kopictureshow.com">

	<title>The Movie Pitch Game!</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Arvo&family=Paprika&family=ABeeZee&family=Acme&family=Comfortaa&family=Lemonada&display=swap" rel="stylesheet"> 
	<link rel="stylesheet" href="pitchgame.css">
	<script type="text/javascript" src="pitchgame.js"></script>
	<?php if ($con->needsCaptcha) { ?>
	<script src="https://www.google.com/recaptcha/api.js" async defer></script>
	<?php } ?>

	<style type='text/css'>
		/* Font notes: Arvo is typewriter-ish but cleaner and not monospace, let's use for input and lit... go heavier?  Patua One needs to go lighter but can't */
		/* Acme is heavy and slanty, usable for prompts and maybe for body, but I'd like it lighter for body use... let's use for prompts */
		/* Nunito is clean and pleasant, good for prompts without adding many style points; ABeeZee is more stylish, ok for body and good for prompts */
		/* Comfortaa/700 is very round and modern, acceptable for body text, should contrast well with arvo? (nope), also nice for prompts but maybe too wide */
		/* Baloo 2 is a bit short and wide for prompts, acceptable for body? */
		/* Lemonada is cursive-ish, okay for body, better for prompts; Paprika is similar but tall, Akaya more stylish, but pinched */
		/* Balsamiq Sans is comic-sansy, fuck it... Genos is pinched and blocky, fuck it... Gluten is also too comicy, fuck it...
		   Patua One is too heavy, fuck it... fuck Boogaloo... Calistoga is too heavy, fuck it... Corben too old-fashioned, fuck it...
		   Fuck Lemonada... fuck Akaya... fuck Comfortaa... fuck Nunito... */
	</style>
</head>



<body>

<?php if (!$databaseFailed && ($pagestate == ASK3_COLD || $pagestate == ASKNAME)) { ?>

<div class=plop id=howtoplay>
	<div class=backer></div>
	<aside>
		<div class=closer><span>×</span></div>

		<?php
		require 'pitchhints.html-content';
		if (SUPPORT_TEAMS)
			require 'pitchhints-team.html-content';
		?>

	</aside>
</div>

<?php } ?>


<main>
	<h1 class=title>The Movie Pitch Game!</h1>
	<h3 class=subtitle>invented by <a href='http://kopictureshow.com' target='_blank'>KO Rob</a></h3>
	
<?php if ($databaseFailed) { ?>

	<h2 class=failure>Error... dang it</h2>
	<p>

	We’re sorry, but the Movie Pitch Game has experienced an internal failure.&ensp;We
	will investigate the problem and try to prevent recurrences.

	</p>
	<div class=exceptional><?=nl2br(enc($con->lastError))?></div>

<?php } else if ($con->isBlocked) { ?>

	<p>We’re sorry, but our moderators have blocked you from further participation in the game.</p>

<?php } else if ($pagestate == ASK3_COLD || $pagestate == ASK3_WARM || $pagestate == ASK3_RVWD || $pagestate == ASK3_NAMD) { ?>

	<p>

	<?php if ($pagestate == ASK3_COLD) { ?>

	This is a game in which you are given a three word story idea, in the form of
	a short sentence with a verb in the middle, like “man bites dog” or “Martians
	invade Belgium”.&ensp;Your job is to pitch a movie based on that idea — that
	is, to describe an unmade film in a paragraph or two, and make it sound like
	something people would want to see.&ensp;For further explanation,
	<a href='' id=showhints>click here</a>.

	</p><p>

	But before you get your three words, you must supply three words for other
	people to use.&ensp;Please give us a noun, a verb, and another noun:

	<?php } else if ($pagestate == ASK3_NAMD) { ?>

	Okay, we’re ready to play!&ensp;To get your three word idea, you have to
	first supply three words for <?=$team ? 'your friends' : 'other people'?>
	to use.&ensp;Please give us a noun, a verb, and another noun:

	<?php } else { ?>

	<i>Thank you for <?=($pagestate == ASK3_RVWD ? "rating" : "reading")?> some of the
	submissions of your fellow players!</i>&ensp;How about playing another round?

	<?php } ?>

	</p>
	<form method="POST" class=fields>
		<input type=hidden name=formtype value="initialwords" />
		<input type=hidden name=variant value='<?=$pagestate?>' />
		<input type=hidden name=seed value="<?=rand(1000000, 2000000000)?>" />
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
	<?php } else if ($dupeWords) { ?>
		<div class=validation>
		<?php if ($dupeWords->allThree) { ?>
			You have already submitted those same three words
			<?=englishCount($dupeWords->allThree)?>.&ensp;Please use different ones.
		<?php } else if ($dupeWords->subjectCount) { ?>
			You have already submitted that first noun “<?=enc($initialSubject)?>”
			<?=englishCount($dupeWords->subjectCount)?>.&ensp;Please use a different one.
		<?php } else if ($dupeWords->verbCount) { ?>
			You have already submitted that verb “<?=enc($initialVerb)?>”
			<?=englishCount($dupeWords->verbCount)?>.&ensp;Please use a different one.
		<?php } else if ($dupeWords->objectCount) { ?>
			You have already submitted that last noun “<?=enc($initialObject)?>”
			<?=englishCount($dupeWords->objectCount)?>.&ensp;Please use a different one.
		<?php } ?>
		</div>
	<?php } ?>
	<?php if ($con->needsCaptcha) { ?>
		<p class="g-recaptcha" data-tabindex=4 data-callback="captchaSatisfied"
		   data-sitekey="<?=CAPTCHA_KEY?>"></p>
		<?php if (isset($_POST['g-recaptcha-response'])) { ?>
		<div class=validation>We’re sorry, that captcha response wasn’t successful.</div>
		<?php } ?>
		<p>
			<button tabindex=5 disabled id=submitter>Submit Words so I can Make My Pitch</button>
		</p>
	<?php } else { ?>
		<p>
			<button tabindex=4>Submit Words so I can Make My Pitch</button>
		</p>
	<?php } ?>
	</form>

<?php } else if ($pagestate == PITCH) { ?>

	<p>

	Thank you.&ensp;Your three word
	<?=$ideaWordCount <= 3 ? '' : '— er, ' . englishNumber($ideaWordCount) . ' word? —'?>
	idea, “<span class=lit><?=enc($idea)?></span>”, will soon be inspiring
	others to come up with creative movie pitches.

	</p><p>

	And now it’s your turn!&ensp;Your story idea is:

	</p>
	<h2 class="challenge lit">
	<?=enc($challengeSummary)?>
	</h2>
	<div class=modery>(<a href='' id=moderato>click here</a> if one or more words are invalid)</div>

	<form method="POST" id=pitchform class=fields>
		<input type=hidden name=formtype id=formtype value='pitch' />
		<input type=hidden name=idea value='<?=enc($idea)?>' />
		<input type=hidden name=challenge value='<?=enc(serialize($challenge))?>' />
		<input type=hidden name=seed value="<?=$seed?>" />
		<div class=chungus>
			<div>
				<label for=title>Movie Title:</label>
				<input type=text id=title name=title class=chungus value='<?=enc($title)?>' maxlength=100 tabindex=1 />
			</div><div>
				<label for=pitch>Pitch! :</label>
				<textarea id=pitch name=pitch maxlength=2000 tabindex=2><?=enc($pitch)?></textarea>
			</div><div style='position: relative'>
				<label class=optionality>(optional)</label>
				<label for=signature>Signature:</label>
				<input type=text id=signature name=signature class="chungus mini" value='<?=enc($signature)?>' maxlength=100 tabindex=3 />
				<span class=postchex><label><input type=checkbox name=defsig value=1 />Use as my default signature</label></span>
			</div>
		</div>
	<?php if ($validationFailed) { ?>
		<div class=validation style='margin-top: 0.6em'>
			Both Movie Title and Pitch text are required!
		</div>
	<?php } ?>
		<p>
			<button id=pitchery tabindex=4>Submit My Pitch!</button>
		</p>
	</form>

<?php } else if ($pagestate == REVIEW) { ?>

	<p>

	Thank you.&ensp;Here are some other folks’ pitches for you to read.&ensp;Enjoy, and if
	you want, leave a star rating for any or all of the pitches below.

	</p>
	<form method="POST">
		<input type=hidden name=formtype value='review' />
		<input type=hidden name=pitchesToReview value='<?=enc(serialize($pitchesToReview))?>' />
		<input type=hidden name=seed value="<?=$seed?>" />
		<button>Submit My Ratings and Play Another Round</button>

	<?php foreach ($pitchesToReview as $pitcher) { ?>
		<blockquote class=pitch>
			<div>
				<i>from the idea “<?=enc($pitcher->subject)?>
			    <?=enc($pitcher->verb)?> <?=enc($pitcher->object)?>”:</i>
			</div>
			<h3 class=favetitle><?=enc($pitcher->title)?></h3>
			<p style='white-space: pre-wrap' class=lit><?=enc($pitcher->pitch)?></p>
			<?php if ($pitcher->signature) echo '<div class=lit>&mdash; ' . enc($pitcher->signature) . "</div>\n"; ?>
			<div class=stars>
				<input type=hidden id='newrating_<?=$pitcher->pitchId?>' name='newrating_<?=$pitcher->pitchId?>' value=''></input>
				<span class=rate>Your rating:</span>
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

<?php } else if ($pagestate == FAVES) { ?>

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
				<i>from the idea “<?=enc($pitcher->subject)?> <?=enc($pitcher->verb)?> <?=enc($pitcher->object)?>”:</i>
			</div>
			<div class=uppergap>
				<h3 class=favetitle>
					<?=enc($pitcher->title)?>
					<span class='star aftergap' title='<?=$pitcher->yourRating?> stars'><?=str_repeat('&#9733;', $pitcher->yourRating)?></span>
				</h3>
		<?php if ($pitcher->ratingCount > 1) { ?>
				<span class=otherstar>(average <?=number_format($pitcher->averageRating, 1)?> stars over <?=$pitcher->ratingCount?> reviews)</span>
		<?php } ?>
			</div>
			<p class=favepitch><?=enc($pitcher->pitch)?></p>
			<?php if ($pitcher->signature) echo '<div>&mdash; ' . enc($pitcher->signature) . "</div>\n"; ?>
		</blockquote>
	<?php } ?>

		<button>Play Another Round</button>

	</form>

<?php } else if ($pagestate == BADWORD) { ?>

	<p>

	Please let us know of any words which are unusable.&ensp;A moderator
	will check each report and delete the invalid words.&ensp;Meanwhile,
	you will return to the pitch writing page with a replacement word
	for each one you check off.

	</p><p>

	You should mark a word invalid if it can’t be used as the correct part of speech
	(for instance “<span class=lit>of</span>” or “<span class=lit>actually</span>” or
	“<span class=lit>tall</span>”), if it’s gibberish (like “<span class=lit>Ggggggg</span>” or
	“<span class=lit>asdfasdf</span>”), if it’s spam (“<span class=lit>lose-weight-fast.zzxx.cwm</span>”
	or “<span class=lit>buy DogeCoin now!</span>”), if it’s an attempted hack
	(such as “<span class=lit>&lt;script src='http://unknown.site/xxx.js'&gt;&lt;script&gt;</span>”
	or “<span class=lit>x'; DROP TABLE pitch_verb;</span>”), if it’s not English
	and wouldn’t be recognized by English speakers (“<span class=lit>Mantergeistmännlichkeit</span>”
	or “<span class=lit>新代载人飞船</span>”), or if it’s hate propaganda or promotes crime.

	</p><p>

	You should <i>not</i> mark words invalid because the sentence it makes is
	grammatically awkward due to mismatched plurals or tenses (“<span class=lit>ichthyosaurs flies
	over pessimism</span>”), because it has adult language (“<span class=lit>Ben Franklin shits on your
	shoe</span>”), or because you would really like an easier idea to pitch.&ensp;The
	inconvenience of trying to make creative sense of a jumbled idea is a core part
	of the game!&ensp;Abuse of this reporting feature will be monitored.
	
	</p><p>

	So with that in mind, please mark the words that are invalid, if any:

	</p>
	<form method="POST" id=pitchform class=fields>
		<input type=hidden name=formtype id=formtype value='reportbad' />
		<input type=hidden name=seed value="<?=$seed?>" />
		<input type=hidden name=idea value='<?=enc($idea)?>' />
		<input type=hidden name=challenge value='<?=enc(serialize($challenge))?>' />
		<input type=hidden name=title value='<?=enc($title)?>' />
		<input type=hidden name=pitch value='<?=enc($pitch)?>' />
		<input type=hidden name=signature value='<?=enc($signature)?>' />
		<p>
			<label><input type=checkbox name=badsubject id=badSubject/> “<span class=lit><?=enc($challenge->subject)?></span>” is not a noun</label>
		</p><p>
			<label><input type=checkbox name=badverb id=badVerb/> “<span class=lit><?=enc($challenge->verb)?></span>” is not a verb</label>
		</p><p>
			<label><input type=checkbox name=badobject id=badObject/> “<span class=lit><?=enc($challenge->object)?></span>” is not a noun</label>
		</p><p>
			<button>Submit and Return to my Pitch</button>
		</p>
	</form>

<?php } else if ($pagestate == ASKNAME) { ?>

	<p>
	<?php if ($team) { ?>

	You’ve been invited to a game with friends!&ensp;In this game, you are given
	a three word story idea, in the form of a short sentence with a verb in the
	middle, like “man bites dog” or “Martians invade Belgium”.&ensp;The words you
	get are each supplied by one if your fellow players.&ensp;Your job is to pitch
	a movie based on that idea — that is, to describe an unmade film in a paragraph
	or two, and make it sound like something people would want to see.&ensp;For
	further explanation, <a href='' id=showhints>click here</a>.

	</p><p>

	But first, we need a name for you, so your teammates know who’s who.

	<?php } else { ?>

	This is a game in which you are given a three word story idea, in the form of
	a short sentence with a verb in the middle, like “man bites dog” or “Martians
	invade Belgium”.&ensp;Your job is to pitch a movie based on that idea — that
	is, to describe an unmade film in a paragraph or two, and make it sound like
	something people would want to see.&ensp;For further explanation,
	<a href='' id=showhints>click here</a>.

	</p><p>

	Since you’re new, please give us a name to call you.

	<?php } ?>

	</p>
	<form method="POST">
		<input type=hidden name=formtype value="name" />
		<div class=narrowgowides>
			<label for=subject>Name:</label>
			<input type=text id=name name=name value='' maxlength=50 tabindex=1 />
		</div>
	<?php if ($validationFailed) { ?>
		<div class=validation>
			A name is required.
		</div>
	<?php } ?>
	<?php if ($con->needsCaptcha) { ?>
		<p class="g-recaptcha" data-tabindex=4 data-callback="captchaSatisfied"
		   data-sitekey="<?=CAPTCHA_KEY?>"></p>
		<?php if (isset($_POST['g-recaptcha-response'])) { ?>
		<div class=validation>We’re sorry, that captcha response wasn’t successful.</div>
		<?php } ?>
		<p>
			<button tabindex=3 disabled id=submitter>Join the Game!</button>
		</p>
	<?php } else { ?>
		<p>
			<button tabindex=2>Join the Game!</button>
		</p>
	<?php } ?>
	</form>

<?php } ?>
</main>

<?php if ($con->hasDebugAccess && ($pagestate == 1 || $pagestate == 2)) { ?>
<p style='margin-top: 2em'>
	<a href='' id=randomize class=meta>randomize</a>
</p>
<?php }
      if ($con->hasDebugAccess) { ?>
<p>
	<a href='' id=showLog class=meta>show DB log</a>
</p>
<p id=theLog class=exceptional style='display: none'>
	<?=nl2br(enc($con->getLog() ?: 'no log entries recorded'))?>
</p>
<?php } ?>

</body>
</html>