/* PENDING CHANGES:
Would it simplify anything to move flag count, mod status, and deleted to child table?
Date fields for moderation actions?
*/


-- ==== Do not deploy this file to the website. ====  This is for initial setup of the mysql database.

CREATE DATABASE pitchgame;
ALTER DATABASE pitchgame DEFAULT CHARACTER SET utf8mb4 DEFAULT COLLATE utf8mb4_unicode_520_ci;
-- Manually set the password and put it in php.ini


CREATE TABLE pitchgame.subjects (
  subject_id          INT           NOT NULL AUTO_INCREMENT,
  word                VARCHAR(50)   NOT NULL,
  shown_ct            INT           NOT NULL DEFAULT 0,
  last_shown          DATETIME      DEFAULT current_timestamp(),
  moderation_flag_ct  INT           NOT NULL DEFAULT 0,
  moderation_status   VARCHAR(10)   DEFAULT NULL,
  is_deleted          BOOLEAN       NOT NULL DEFAULT 0,
  is_private          BOOLEAN       NOT NULL DEFAULT 0,

  PRIMARY KEY            (subject_id),
  UNIQUE KEY word        (word),
  KEY last_shown         (last_shown)
);

CREATE TABLE pitchgame.verbs (
  verb_id             INT           NOT NULL AUTO_INCREMENT,
  word                VARCHAR(50)   NOT NULL,
  shown_ct            INT           NOT NULL DEFAULT 0,
  last_shown          DATETIME      DEFAULT current_timestamp(),
  moderation_flag_ct  INT           NOT NULL DEFAULT 0,
  moderation_status   VARCHAR(10)   DEFAULT NULL,
  is_deleted          BOOLEAN       NOT NULL DEFAULT 0,
  is_private          BOOLEAN       NOT NULL DEFAULT 0,

  PRIMARY KEY            (verb_id),
  UNIQUE KEY word        (word),
  KEY last_shown         (last_shown)
);

CREATE TABLE pitchgame.objects (
  object_id           INT           NOT NULL AUTO_INCREMENT,
  word                VARCHAR(50)   NOT NULL,
  shown_ct            INT           NOT NULL DEFAULT 0,
  last_shown          DATETIME      DEFAULT current_timestamp(),
  moderation_flag_ct  INT           NOT NULL DEFAULT 0,
  moderation_status   VARCHAR(10)   DEFAULT NULL,
  is_deleted          BOOLEAN       NOT NULL DEFAULT 0,
  is_private          BOOLEAN       NOT NULL DEFAULT 0,

  PRIMARY KEY            (object_id),
  UNIQUE KEY word        (word),
  KEY last_shown         (last_shown)
);

CREATE TABLE pitchgame.pitches (
  pitch_id            INT           NOT NULL AUTO_INCREMENT,
  title               VARCHAR(100)  NOT NULL,
  pitch               TEXT          NOT NULL,
  signature           VARCHAR(100)  NOT NULL,
  session_id          INT           NOT NULL,
  subject_id          INT           NOT NULL,
  verb_id             INT           NOT NULL,
  object_id           INT           NOT NULL,
  when_submitted      DATETIME      NOT NULL DEFAULT current_timestamp(),
  shown_ct            INT           NOT NULL DEFAULT 0,
  last_shown          DATETIME      DEFAULT current_timestamp(),
  moderation_flag_ct  INT           NOT NULL DEFAULT 0,
  moderation_status   VARCHAR(10)   DEFAULT NULL,
  is_deleted          BOOLEAN       NOT NULL DEFAULT 0,
  is_private          BOOLEAN       NOT NULL DEFAULT 0,

  PRIMARY KEY            (pitch_id),
  UNIQUE KEY no_dupes    (session_id, subject_id, verb_id, object_id)
  KEY title              (title),
  KEY last_shown         (last_shown),
  KEY pitch_subject      (subject_id),
  KEY pitch_verb         (verb_id),
  KEY pitch_object       (object_id),
  CONSTRAINT pitch_session FOREIGN KEY (session_id) REFERENCES objects (session_id),
  CONSTRAINT pitch_subject FOREIGN KEY (subject_id) REFERENCES subjects (subject_id),
  CONSTRAINT pitch_verb    FOREIGN KEY (verb_id)    REFERENCES verbs (verb_id),
  CONSTRAINT pitch_object  FOREIGN KEY (object_id)  REFERENCES objects (object_id)
);

CREATE TABLE pitchgame.teams (
  team_id             INT           NOT NULL AUTO_INCREMENT,
  when_created        DATETIME      NOT NULL DEFAULT current_timestamp(),
  token               VARCHAR(40)   NOT NULL,     -- also add a secret token? or just hash the public one?
  use_ct              INT           NOT NULL DEFAULT 0,
  is_private          BOOLEAN       NOT NULL DEFAULT 0,

  PRIMARY KEY            (team_id)
);

CREATE TABLE pitchgame.sessions (
  session_id          INT           NOT NULL AUTO_INCREMENT,
  when_created        DATETIME      NOT NULL DEFAULT current_timestamp(),
  when_last_used      DATETIME      NOT NULL DEFAULT current_timestamp(),
  nickname            VARCHAR(50)   DEFAULT NULL COMMENT 'may be set at first login; secondary default for pitches.signature',
  signature           VARCHAR(100)  DEFAULT NULL COMMENT 'acts as default for pitches.signature',
  ip_address          VARCHAR(40)   DEFAULT NULL COMMENT 'for spam moderation only',
  useragent           VARCHAR(1000) DEFAULT NULL COMMENT 'for spam moderation only',
  cookie_token        VARCHAR(40)   DEFAULT NULL,
  passed_captcha      BOOLEAN       NOT NULL DEFAULT 0,
  sso_provider        VARCHAR(100)  DEFAULT NULL,        -- not implemented, long term
  sso_name            VARCHAR(100)  DEFAULT NULL,        -- not implemented, long term
  is_test             BOOLEAN       NOT NULL DEFAULT 0,  -- remove?
  has_debug_access    BOOLEAN       NOT NULL DEFAULT 0,
  blocked_by          INT           DEFAULT NULL,
  when_last_reviewed  DATETIME      DEFAULT NULL,

  PRIMARY KEY            (session_id),
  KEY when_last_used     (when_last_used),
  KEY cookie_token       (cookie_token),
  CONSTRAINT session_block FOREIGN KEY (blocked_by) REFERENCES sessions (session_id)
);

CREATE TABLE pitchgame.participations (
  session_id          INT           NOT NULL,
  team_id             INT           NOT NULL,
  use_ct              INT           NOT NULL DEFAULT 0,
  last_used           DATETIME      NOT NULL DEFAULT current_timestamp(),

  KEY part_session    (session_id),
  KEY part_team       (team_id),
  CONSTRAINT part_session FOREIGN KEY (session_id) REFERENCES sessions (session_id),
  CONSTRAINT part_team    FOREIGN KEY (team_id)    REFERENCES teams    (team_id)
);

CREATE TABLE pitchgame.ratings (
  rating_id           INT           NOT NULL AUTO_INCREMENT,
  session_id          INT           NOT NULL,
  pitch_id            INT           NOT NULL,
  rating              TINYINT(2)    NOT NULL COMMENT '1 to 4, and -1 means mark as spam',
  when_rated          DATETIME      NOT NULL DEFAULT current_timestamp(),

  PRIMARY KEY            (rating_id),
  UNIQUE KEY no_dupes    (pitch_id, session_id),
  KEY rating_session     (session_id),
  KEY rating_pitch       (pitch_id),
  CONSTRAINT rating_session FOREIGN KEY (session_id) REFERENCES sessions (session_id),
  CONSTRAINT rating_pitch   FOREIGN KEY (pitch_id)   REFERENCES pitches (pitch_id)
);

CREATE TABLE pitchgame.suggestions (
  suggestion_id       INT           NOT NULL AUTO_INCREMENT,
  session_id          INT           NOT NULL,
  subject_id          INT           NOT NULL,
  verb_id             INT           NOT NULL,
  object_id           INT           NOT NULL,
  when_suggested      DATETIME      NOT NULL DEFAULT current_timestamp(),

  PRIMARY KEY            (suggestion_id),
  UNIQUE KEY no_dupes    (session_id, subject_id, verb_id, object_id)
  KEY suggestion_session (session_id),
  KEY suggestion_subject (subject_id),
  KEY suggestion_verb    (verb_id),
  KEY suggestion_object  (object_id),
  CONSTRAINT suggestion_session        FOREIGN KEY (session_id)  REFERENCES sessions (session_id),
  CONSTRAINT suggestion_subject        FOREIGN KEY (subject_id)  REFERENCES subjects (subject_id),
  CONSTRAINT suggestion_verb           FOREIGN KEY (verb_id)     REFERENCES verbs (verb_id),
  CONSTRAINT suggestion_object         FOREIGN KEY (object_id)   REFERENCES objects (object_id)
);

CREATE TABLE pitchgame.moderations (
  moderation_id       INT           NOT NULL AUTO_INCREMENT,
  session_id          INT           NOT NULL COMMENT 'track people''s moderation requests to detect abuse',
  subject_id          INT           DEFAULT NULL,
  verb_id             INT           DEFAULT NULL,
  object_id           INT           DEFAULT NULL,
  pitch_id            INT           DEFAULT NULL,
  when_submitted      DATETIME      NOT NULL DEFAULT current_timestamp(),
  accepted_by         INT           DEFAULT NULL COMMENT 'a request may be both accepted and rejected if it flags more than one word',
  rejected_by         INT           DEFAULT NULL,

  PRIMARY KEY                   (moderation_id),
  UNIQUE KEY no_dupes           (session_id, subject_id, verb_id, object_id, pitch_id)
  KEY moderation_session        (session_id),
  KEY moderation_subject        (subject_id),
  KEY moderation_verb           (verb_id),
  KEY moderation_object         (object_id),
  KEY moderation_pitch          (pitch_id),
  KEY suggestion_session_accept (suggestion_session_accept),
  KEY suggestion_session_reject (suggestion_session_reject),
  CONSTRAINT moderation_session        FOREIGN KEY (session_id)  REFERENCES sessions (session_id),
  CONSTRAINT moderation_subject        FOREIGN KEY (subject_id)  REFERENCES subjects (subject_id),
  CONSTRAINT moderation_verb           FOREIGN KEY (verb_id)     REFERENCES verbs (verb_id),
  CONSTRAINT moderation_object         FOREIGN KEY (object_id)   REFERENCES objects (object_id),
  CONSTRAINT moderation_pitch          FOREIGN KEY (pitch_id)    REFERENCES pitches (pitch_id),
  CONSTRAINT suggestion_session_accept FOREIGN KEY (accepted_by) REFERENCES sessions (session_id),
  CONSTRAINT suggestion_session_reject FOREIGN KEY (rejected_by) REFERENCES sessions (session_id)
);
