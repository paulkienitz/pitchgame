<!DOCTYPE html>
<?php
// This page is for moderators and administrators.  Set it to be accessible only with a password.

// TODO: add new moderation option "Doesn't Fit Suggestion"?

require 'pitchdata-admin.php';
require 'common.php';

define('PENDING',   0);
define('POPUP',     1);
define('HISTORY',   2);
define('UNDOBLOCK', 3);
define('PURGE',     4);

$pagestate = PENDING;
$con = new PitchGameAdminConnection();      // defined in pitchdata_admin.php

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
$everybodyIsSuspicious = false;
$history = null;


function warn(?int $number, string $description, ?string $descriptionSingular = null)
{
    if ($number <= 0)
        return '0 ' . enc($description);
    else if ($number == 1)
    {
        $descriptionSingular ??= $description;
        return '<span class=warn>1 ' . enc($descriptionSingular) . '</span>';
    }
    else
        return "<span class=warn>$number " . enc($description) . '</span>';
}

function countAndRange(int $count, ?string $earliest, ?string $latest)
{
    if ($count > 1 && $earliest != $latest)
        return "$count&ensp;from&ensp;" . enc($earliest) . '&ensp;to&ensp;' . enc($latest);
    else
        return $count > 0 ? "$count&ensp;at&ensp;" . enc($earliest) : 'none';
}

function showFlagDelMod(int $shown, int $flagged, int $deleted, int $moderated)
{
    return $shown . ($shown == 1 ? ' view, ' : ' views, ') . warn($flagged, 'flags', 'flag') . ', ' .
           warn($deleted, 'deleted') . ', ' . warn($moderated, 'moderated');
}

function slink(int $sessionId, ?string $name)
{
    return $name ? "<a href='' id='session_$sessionId' class=slinky>" . enc($name) . " ($sessionId)</a>"
                 : "<a href='' id='session_$sessionId' class=slinky>user $sessionId</a>";
}

function attribution(int $id, string $when, int $sessionId, ?string $name, int $flagCount)
{
    return "$id, submitted " . enc($when) . ' by ' . slink($sessionId, $name) .
           ($flagCount > 1 ? ", has $flagCount flags" : '');
}

function ject(?int $rejectedBy, ?int $acceptedBy, ?string $rejectNickname)
{
    if (!$rejectedBy)
        return '';
    $rl = slink($rejectedBy, $rejectNickname);
    return !$acceptedBy ? " — <span class=warn>REJECTED by $rl</span>"
                        : " — <span class=warn>PARTIALLY REJECTED by $rl</span>";
}

function judgment(?string $modStatus, ?bool $deleted, string $prefix, string $postfix)
{
    return !$modStatus && !$deleted ? '' :
           "$prefix<span class=" . ($modStatus == 'Valid' ? 'antiwarn' : 'warn') . '>' .
           ($deleted ? 'DELETED as ' . enc($modStatus) : 'judged ' . enc($modStatus)) . "</span>$postfix\n";
}

function histatus(HistoryPart $hp)
{
    return !is_numeric($hp->shownCount) ? judgment($hp->modStatus, $hp->deleted, '&ensp;(', ')')
           : " — $hp->shownCount " . ($hp->shownCount == 1 ? 'view, ' : 'views, ') .
             warn($hp->flagCount, 'flags', 'flag') . judgment($hp->modStatus, $hp->deleted, ' — ', '');
}

function histword(string $part, ?string $word, HistoryPart $hp)
{
    return !$word ? '' :
           '<br/>' . enc($part) . ' <span class="lit' . ($hp->deleted ? ' deleted' : '') . '">“' . enc($word) . '”</span>' . histatus($hp);
}

function askWord(string $kind, int $id, string $when, int $sessionId, ?string $name, int $flagCount, int $dupes, string $word, int $moderationId)
{
    $rat = "type=radio name='modreq_$kind[0]_$moderationId'";
    $partOfSpeech = $kind == 'Verb' ? 'verb' : 'noun';
    return '<div class=uppergap>— ' . enc($kind) . ' ' . attribution($id, $when, $sessionId, $name, $flagCount) .
           ($dupes > 1 ? ", $dupes dupes" : '') .
           "</div>\n<div class=qq>Is <span class=lit>“" . enc($word) .
           "”</span> a valid $partOfSpeech?</div>\n<div>" .
           "<label><input $rat value='Valid' /> Valid</label>" .
           "<label><input $rat value='Dubious' /> Dubious</label>" .
           "<label><input $rat value='Misspelled' /> Misspelled</label>" .
           "<label><input $rat value='Non-$partOfSpeech' /> Not a $partOfSpeech, DELETE</label>" .
           "<label><input $rat value='Gibberish' /> Gibberish, DELETE</label>" .
           "<label><input $rat value='Spam' /> Spam, DELETE</label>" .
           "<label><input $rat value='Hacking' /> Hacking, DELETE</label>" .
           "<label><input $rat value='Evil' /> Hate or Crime, DELETE</label>" .
           "<label><input $rat value='' checked /> (no answer)</label></div>\n";
}


// ---- process get/post args and do DB operations

if (!$databaseFailed)
    $databaseFailed = !connectToSession($con);
else
    $con->lastError = "Database connection failed - $con->lastError";

if (isset($_GET['sessionId']) && !$databaseFailed)
{
    $sessionId = (int) $_GET['sessionId'];
    $pagestate = 1;
}
else if (isset($_POST['formtype']) && !$databaseFailed)     // extract form values, validate, and update
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
        $pagestate = PENDING;
        $moderationRequests = null;     // reload a fresh set
    }
    else if ($_POST['formtype'] == 'usualsuspects')
    {
        $suspiciousUserDays = (int) $_POST['daysold'] ?: 3;
        $everybodyIsSuspicious = (bool) (int) $_POST['suspicion'];
        $pagestate = PENDING;
    }
    else if ($_POST['formtype'] == 'suspectmoreorless')
    {
        $suspiciousUserDays = (int) $_POST['daysold'] ?: 3;
        $everybodyIsSuspicious = !(bool) (int) $_POST['suspicion'];
        $pagestate = PENDING;
    }
    else if ($_POST['formtype'] == 'history')
    {
        $sessionId = (int) $_POST['sessionid'];
        $suspiciousUserDays = (int) $_POST['daysold'];
        $pagestate = HISTORY;
    }
    else if ($_POST['formtype'] == 'judgeuser')
    {
        $sessionId = (int) $_POST['sessionid'];
        $suspiciousUserDays = (int) $_POST['daysold'];
        $disposal = $_POST['disposal'];
        if ($disposal == 'purge')
            $pagestate = PURGE;
        else
        {
            $history = unserialize($_POST['history']);
            foreach ($history as $h)
            {
                if ($h->p->live())
                {
                    if (!!$_POST["attn_p{$h->what->pitchId}"])
                        if ($con->ratePitch($h->what->pitchId, -2))
                            ++$pitchesFlagged;
                        else
                            $databaseFailed = true;
                }
                else if (!!$_POST["attn_g$h->suggestionId"])
                {
                    if ($con->flagWordsForModeration($h->what->c, $h->s->live(), $h->v->live(), $h->o->live()))
                        $wordsFlagged += (int) $h->s->live() + (int) $h->v->live() + (int) $h->o->live();
                    else
                        $databaseFailed = true;
                }
            }
            if ($disposal == 'block' && !$databaseFailed)
            {
                $databaseFailed = !$con->blockSession($sessionId, true);
                $pagestate = UNDOBLOCK;
            }
            else
            {
                $databaseFailed = !$con->blockSession($sessionId, false);   // sets when_last_reviewed
                $pagestate = PENDING;
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
        $pagestate = PENDING;
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
            $pagestate = PENDING;
        }
        else
            $pagestate = HISTORY;
    }
}
// make sure each page view has its necessary data
if (!$databaseFailed)
{
    if (($pagestate == POPUP || $pagestate == HISTORY || $pagestate == PURGE) && !$userSummary)
    {
        $userSummary = $con->sessionStats($sessionId);
        $databaseFailed = !$userSummary;
    }
    if ($pagestate == PENDING && !$moderationRequests)
    {
        $moderationRequests = $con->getRecentModerationRequests();
        $databaseFailed = !is_array($moderationRequests);
    }
    else if ($pagestate == HISTORY && !$history && !$databaseFailed)
    {
        $history = $con->getSessionHistory($sessionId);
        $databaseFailed = !is_array($history);
    }
    if ($pagestate == PENDING && !$databaseFailed && !count($moderationRequests))
    {
        $suspiciousSessions = $con->getSuspiciousSessions($suspiciousUserDays, $everybodyIsSuspicious);
        $databaseFailed = !is_array($suspiciousSessions);
    }
}

header('cache-control: no-cache');
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
    <script type="text/javascript" src="spare03.js"></script>
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
    <?php if ($pagestate != PENDING) { ?>
    <a id=backToList href='/pitchgame/moderator.php'>Return to moderation list</a>
    <?php } ?>
<p>
    
<?php if ($databaseFailed) { ?>

    <h2 class=failure>Error... dang it</h2>
    <p>

    We’re sorry, but the Movie Pitch Game admin page has experienced an internal failure.&ensp;We
    will investigate the problem and try to prevent recurrences.

    </p>
    <div class=exceptional><?=nl2br(enc($con->lastError))?></div>

<?php } else if ($pagestate == PENDING && $moderationRequests && count($moderationRequests)) { ?>

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

    <p>Here are the most recent moderation requests — ideas marked as non-words, pitches
    marked as spam, etc.&ensp;Please check each one for validity.</p>

    <?php } ?>
    </p>
    <form method="POST" class=meta>
        <input type=hidden name=formtype id=formtype value='judgerequests' />
        <input type=hidden name=sessionid id=lastSessionId value='' />
        <input type=hidden name=moderationrequests value="<?=enc(serialize($moderationRequests))?>" />
    <?php foreach ($moderationRequests as $modreq) { ?>
        <div class=fields>
            <div>
                #<?=$modreq->moderationId?> »&ensp;At <?=enc($modreq->whenRequested)?>,
                <?=slink($modreq->requestorSessionId, $modreq->requestorName)?>
                <!--?=$modreq->flagDupes > 2 ? '(and ' . englishNumber($modreq->flagDupes - 1) . ' others)' :
                  ($modreq->flagDupes > 1 ? '(and one other)' : '')?-->
                flagged this:
            </div>
        <?php if ($modreq->p->pitchId) { ?>
            <p>
                — Pitch <?=attribution($modreq->p->pitchId, $modreq->pit->when, $modreq->pit->sessionId, $modreq->pit->nickname, $modreq->pit->flagCount)?>:
            </p>
            <blockquote>
                Idea: <span class=lit>“<?=enc($modreq->p->c->subject)?> <?=enc($modreq->p->c->verb)?> <?=enc($modreq->p->c->object)?>”</span>
            </blockquote>
            <blockquote>
                <div>Title: <span class=lit>“<?=enc($modreq->p->title)?>”</span></div>
                <div>Pitch: <span class=lit>“<?=enc($modreq->p->pitch)?>”</div>
                <div>Signature: <?=$modreq->p->signature ? '<span class=lit>“' . enc($modreq->p->signature) . '”</span>' : '(none)'?></div>
            </blockquote>
            <div class=qq>Is that a valid pitch?</div>
            <div>
            <label><input type=radio name='modreq_P_<?=$modreq->moderationId?>' value='Valid' /> Valid</label>
            <label><input type=radio name='modreq_P_<?=$modreq->moderationId?>' value='Dubious' /> Dubious</label>
            <label><input type=radio name='modreq_P_<?=$modreq->moderationId?>' value='Gibberish' /> Gibberish, DELETE</label>
            <label><input type=radio name='modreq_P_<?=$modreq->moderationId?>' value='Spam' /> Spam, DELETE</label>
            <label><input type=radio name='modreq_P_<?=$modreq->moderationId?>' value='Hacking' /> Hacking, DELETE</label>
            <label><input type=radio name='modreq_P_<?=$modreq->moderationId?>' value='Evil' /> Hate or Crime, DELETE</label>
            <label><input type=radio name='modreq_P_<?=$modreq->moderationId?>' value='' checked /> (no answer)</label>
            </div>
        <?php } else {
            if ($modreq->p->c->subjectId)
                echo askWord('Subject noun', $modreq->p->c->subjectId, $modreq->sub->when, $modreq->sub->sessionId, $modreq->sub->nickname,
                             $modreq->sub->flagCount, $modreq->sub->dupes, $modreq->p->c->subject, $modreq->moderationId);
            if ($modreq->p->c->verbId)
                echo askWord('Verb', $modreq->p->c->verbId, $modreq->vrb->when, $modreq->vrb->sessionId, $modreq->vrb->nickname,
                             $modreq->vrb->flagCount, $modreq->vrb->dupes, $modreq->p->c->verb, $modreq->moderationId);
            if ($modreq->p->c->objectId)
                echo askWord('Object noun', $modreq->p->c->objectId, $modreq->obj->when, $modreq->obj->sessionId, $modreq->obj->nickname,
                             $modreq->obj->flagCount, $modreq->obj->dupes, $modreq->p->c->object, $modreq->moderationId);
        } ?>
        </div>
    <?php } ?>
        <p>
            <button id=pronounce>Pronounce your Judgments</button>
        </p>
    </form>

<?php } else if ($pagestate == PENDING) { ?>

    <?php if ($resolved) { ?>

    <p>Thank you for handling <?=$resolved == 1 ? 'that moderation request' :
           'those ' . englishNumber($resolved) . ' moderation requests' ?>.&ensp;There
       are no longer any outstanding requests.</p>
    
    <?php } else { ?>

    <p>No outstanding moderation requests found.&ensp;Thanks for checking.</p>

    <?php } ?>

    <p>So instead, here is a list of active users who have had one or more of
    their word or pitch submissions deleted, or who’ve flagged someone else’s
    input for reasons found invalid, since the last time someone reviewed them
    here.&ensp;You can review their histories and see if they have committed
    new offenses.&ensp;Or, you can switch to just showing all recently active users.</p>

    <form method="POST" class="meta direct">
        <input type=hidden name=formtype id=formtype value='usualsuspects' />
        <input type=hidden name=sessionid id=lastSessionId value='' />
        <input type=hidden name=suspicion value='<?=$everybodyIsSuspicious ? 1 : 0?>' />

        <p>Showing users active in the last
        <select name=daysold id=daysOldDropdown>
            <option value=3 <?=$suspiciousUserDays <= 5 ? 'selected' : ''?>>3</option>
            <option value=10 <?=$suspiciousUserDays > 5 && $suspiciousUserDays < 20 ? 'selected' : ''?>>10</option>
            <option value=30 <?=$suspiciousUserDays >= 20 && $suspiciousUserDays < 60 ? 'selected' : ''?>>30</option>
            <option value=100 <?=$suspiciousUserDays >= 60 ? 'selected' : ''?>>100</option>
            <option value=365 <?=$suspiciousUserDays >= 200 ? 'selected' : ''?>>365</option>
        </select>
        days:</p>

        <?php foreach ($suspiciousSessions as $sus) { ?>
            <p>
                <?=$sus->deletions?> deletions, active <?=enc($sus->whenLastUsed)?>: <?=slink($sus->sessionId, $sus->nickname)?>
                <?=$sus->signature && $sus->signature != $sus->nickname ? '— sig “' . enc($sus->signature) . '”' : '' ?>
            </p>
        <?php } ?>
        <?php if (!count($suspiciousSessions)) { ?>
            <p>None found — all have already been reviewed.</p>
        <?php } ?>
        <?php if (!$everybodyIsSuspicious) { ?>
            <p><a href='' id=suspectmoreorless>Click here</a> to cast a wider net and include
               active users who do not have any misbehavior reported.</p>
        <?php } else { ?>
            <p><a href='' id=suspectmoreorless>Click here</a> to narrow the search and include
               only users who have had misbehavior reported.</p>
        <?php } ?>
    </form>

<?php } else if (($pagestate == POPUP || $pagestate == HISTORY) && !$userSummary) { ?>

    <div id=userSummary>
        <p>User <?=enc($_GET['sessionId'] ?: $_POST['sessionid'])?> not found.</p>   <!-- XXX add a hash to prevent enumeration? -->
    </div>

<?php } else if ($pagestate == POPUP || $pagestate == HISTORY) { ?>

    <?php if ($pagestate == 1) { ?>
    <form method="POST" action='moderator.php'>
        <input type=hidden name=formtype id=formtype value='history' />
        <input type=hidden name=sessionid value='<?=$sessionId?>' />
    </form>
    <?php } ?>
    <div id=userSummary>
        <table class=userSummary>
            <tr><td>Session status:</td>
                <td>#<?=$sessionId?> is <?=$userSummary->blockedBy ? "<span class=warn>BLOCKED by $userSummary->blockedBy</span>"
                                                                   : ($userSummary->isDebugger ? 'DEBUGGER'
                                                                      : ($userSummary->isTest ? 'TESTER' : 'active'))?>
                    — <?=$userSummary->signature && $userSummary->signature != $userSemmary->nickname
                         ? 'signature <span class=lit>“' . enc($userSummary->signature) . ($userSummary->nickname ? '”</span>, name <span class=lit>“' . enc($userSummary->nickname) : '') . '”</span>'
                         : ($userSummary->nickname ? '<span class=lit>“' . enc($userSummary->nickname) . '”</span>' : 'no name or signature')?></td>
            </tr>
            <tr><td>Dates active:</td>
                <td>created&ensp;<?=enc($userSummary->whenCreated)?>,&ensp;last used&ensp;<?=enc($userSummary->whenLastUsed)?></td>
            </tr>
            <tr><td>Last connection:</td>
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
                <td><?=countAndRange($userSummary->modReqsCount, $userSummary->modReqsEarliest, $userSummary->modReqsLatest)?></td>
            </tr>
    <?php if ($userSummary->modReqsCount) { ?>
            <tr><td>Moderation outcomes:</td>
                <td><?=$userSummary->modReqsAcceptedCount?> accepted<?=$userSummary->modReqsSplitCount > 0 ? " ($userSummary->modReqsSplitCount split)" : ''?>,
                <?=$userSummary->modReqsRejectedCount?> rejected, <?=$userSummary->modReqsPendingCount?> pending</td>
            </tr>
    <?php } ?>
    <?php if ($userSummary->whenLastReviewed) { ?>
            <tr><td>History reviewed:</td>
                <td>checked by moderator <?=enc($userSummary->whenLastReviewed)?></td>
            </tr>
    <?php } ?>
        </table>
    <?php if ($pagestate == POPUP) { ?>
        <p>
            <a href='' id=historicize>View full submission history</a>
        </p>
    <?php }
          $watermark = $userSummary->whenLastReviewedUnixTime; ?>
    </div>

    <?php if ($pagestate == HISTORY) { ?>
    
    <h2>History of user <?=$sessionId?>’s ideas and pitches:</h2>
    <p>

    Here is everything they’ve submitted, from most recent to oldest.&ensp;If you
    see that the user is mainly making an honest effort, you can use the “Flag for
    further attention” checkbox to flag the bad cases for moderation.&ensp;Then you
    can either block them, or let them continue — either way, they are marked as
    having been reviewed and will disappear from the suspicious users list.&ensp;If
    there’s plenty of bad and not much good here, then there’s no need to take the
    trouble of flagging each individual offense... in that case, there’s an option
    to completely purge everything the user has submitted.

    </p>
    <?php if ($userSummary->isTest || $userSummary->isDebugger) { ?>
    <p>
    <strong>NOTE that this user <?=$userSummary->isDebugger ? 'has debug access' : 'is an official tester'?>.</strong>&ensp;Bad
    submissions may have been entered as part of testing.
    </p>
    <?php } ?>
    <form method="POST" class=meta>
        <input type=hidden name=formtype id=formtype value='judgeuser' />
        <input type=hidden name=sessionid id=lastSessionId value='<?=$sessionId?>' />
        <input type=hidden name=daysold value='<?=$suspiciousUserDays?>' />
        <input type=hidden name=history value='<?=enc(serialize($history))?>' />
        <?php foreach ($history as $h) { ?>
            <?php if ($watermark && $watermark > $h->whenPostedUnixTime) { ?>
        <p>— Reviewed by moderator <?=enc($userSummary->whenLastReviewed)?> —
           <a href='' id=showOldHistory>show earlier history</a> —
        </p>
        <div id=oldHistory style='display: none'>
            <?php $watermark = -1.0; } ?>
        <blockquote class="pitch his <?=$h->deleted() ? ' dark' : ''?><?=$h->rejectedBy ? ' malev' : ($h->acceptedBy ? ' benev' : '')?>">
            <?php if ($h->what->pitchId) { ?>
                <?php if ($h->moderationId) { ?>
                <div class=lowergap>
                    Flagged this pitch <?=$h->whenPosted . judgment($h->p->modStatus, $h->p->deleted, ' (', ')') .
                    ject($h->rejectedBy, $h->acceptedBy, $h->rejectNickname)?>
                </div>
                <?php } else { ?>
                <div class=lowergap>
                    Written <?=enc($h->whenPosted)?><?=histatus($h->p)?>
                </div>
                <?php } ?>
                <div <?=$h->p->deleted ? 'class=deleted' : ''?>>
                    <div style="margin-bottom: 1em">Idea: <span class=lit>“<?=enc($h->what->c->subject)?> <?=enc($h->what->c->verb)?> <?=enc($h->what->c->object)?>”</span></div>
                    <div>Title: <span class=lit>“<?=enc($h->what->title)?>”</span></div>
                    <div>Pitch: <span class=lit>“<?=enc($h->what->pitch)?>”</div>
                    <div>Signature: <?=$h->what->signature ? '<span class=lit>“' . enc($h->what->signature) . '”</span>' : '(none)'?></div>
                </div>
                <?php if ($h->p->live()) { ?>
                    <label class=uppergap><input type=checkbox name='attn_p<?=$h->what->pitchId?>' value=1 /> Flag pitch for further attention</label>
                <?php } ?>
            <?php } else { ?>
                <?php if ($h->moderationId) { ?>
                <div>Flagged bad word <?=$h->whenPosted . ject($h->rejectedBy, $h->acceptedBy, $h->rejectNickname)?></div>
                <?php } else { ?>
                <div>Submitted <?=$h->whenPosted?></div>
                <?php } ?>
                <div>
                    <?=histword('Subject', $h->what->c->subject, $h->s)?>
                    <?=histword('Verb',    $h->what->c->verb,    $h->v)?>
                    <?=histword('Object',  $h->what->c->object,  $h->o)?>
                </div>
                <?php if ($h->s->live() || $h->v->live() || $h->o->live()) { ?>
                    <div class=uppergap>
                        <label><input type=checkbox name='attn_g<?=$h->suggestionId?>' value=1 /> Flag words for further attention</label>
                    </div>
                <?php } ?>
            <?php } ?>
        </blockquote>
        <?php }
              if ($watermark)
                  echo("</div>\n"); ?>

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

<?php } else if ($pagestate == UNDOBLOCK) { ?>

    <p>Thank you.&ensp;<?=$wordsFlagged?> words and <?=$pitchesFlagged?> pitches have been added to
    the moderation list.&ensp;User <?=$sessionId?> is now blocked.</p>

    <form method=POST>
        <input type=hidden name=formtype value='blocked' />
        <input type=hidden name=sessionid value='<?=$sessionId?>' />
        <button name=block value=undo>Woops, undo the block!</button><br/>
        <button name=block value=okay>OK, return to moderation list</button>
    </form>

<?php } else if ($pagestate == PURGE) { ?>

    <p>Are you sure you want to purge everything submitted by user <?=$sessionId?>?&ensp;If you
    confirm, all of the words and pitches listed on the previous page will be deleted, and
    they will also be blocked from further participation.</p>

    <p>The purge will delete <?=$userSummary->pitchesCount - $userSummary->pitchesDeletedCount?>
    pitches (<?=$userSummary->pitchesDeletedCount?> are already deleted).&ensp;It will delete up to
    <?=3 * $userSummary->ideasCount - $userSummary->wordsDeletedCount?> nouns and verbs, omitting
    any also used by other players (<?=$userSummary->wordsDeletedCount?> are already
    deleted).&ensp;It will also reject <?=$userSummary->modReqsPendingCount?> pending moderation
    requests (<?=$userSummary->modReqsRejectedCount?> already rejected).</p>

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
