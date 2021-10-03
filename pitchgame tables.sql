CREATE DATABASE pitchgame;
ALTER DATABASE pitchgame DEFAULT CHARACTER SET utf8mb4 DEFAULT COLLATE utf8mb4_unicode_520_ci;


CREATE TABLE pitchgame.subjects (
  subject_id          int           NOT NULL AUTO_INCREMENT,
  word                varchar(50)   NOT NULL,
  shown_ct            int           NOT NULL DEFAULT 0,
  last_shown          datetime      DEFAULT current_timestamp(),
  moderation_flag_ct  int           NOT NULL DEFAULT 0,
  moderation_status   varchar(10)   DEFAULT NULL,
  is_deleted          boolean       NOT NULL DEFAULT 0,
  PRIMARY KEY            (subject_id),
  UNIQUE KEY word        (word),
  KEY last_shown         (last_shown),
  KEY moderation_status  (moderation_status)
);

CREATE TABLE pitchgame.verbs (
  verb_id             int           NOT NULL AUTO_INCREMENT,
  word                varchar(50)   NOT NULL,
  shown_ct            int           NOT NULL DEFAULT 0,
  last_shown          datetime      DEFAULT current_timestamp(),
  moderation_flag_ct  int           NOT NULL DEFAULT 0,
  moderation_status   varchar(10)   DEFAULT NULL,
  is_deleted          boolean       NOT NULL DEFAULT 0,
  PRIMARY KEY            (verb_id),
  UNIQUE KEY word        (word),
  KEY last_shown         (last_shown),
  KEY moderation_status  (moderation_status)
);

CREATE TABLE pitchgame.objects (
  object_id           int           NOT NULL AUTO_INCREMENT,
  word                varchar(50)   NOT NULL,
  shown_ct            int           NOT NULL DEFAULT 0,
  last_shown          datetime      DEFAULT current_timestamp(),
  moderation_flag_ct  int           NOT NULL DEFAULT 0,
  moderation_status   varchar(10)   DEFAULT NULL,
  is_deleted          boolean       NOT NULL DEFAULT 0,
  PRIMARY KEY            (object_id),
  UNIQUE KEY word        (word),
  KEY last_shown         (last_shown),
  KEY moderation_status  (moderation_status)
);

CREATE TABLE pitchgame.pitches (
  pitch_id            int           NOT NULL AUTO_INCREMENT,
  title               varchar(100)  NOT NULL,
  pitch               text          NOT NULL,
  signature           varchar(100)  NOT NULL,
  session_id          int           NOT NULL,
  subject_id          int           NOT NULL,
  verb_id             int           NOT NULL,
  object_id           int           NOT NULL,
  shown_ct            int           NOT NULL DEFAULT 0,
  last_shown          datetime      DEFAULT current_timestamp(),
  moderation_flag_ct  int           NOT NULL DEFAULT 0,
  moderation_status   varchar(10)   DEFAULT NULL,
  is_deleted          boolean       NOT NULL DEFAULT 0,
  PRIMARY KEY            (pitch_id),
  UNIQUE KEY no_dupes    (session_id, subject_id, verb_id, object_id)
  KEY title              (title),
  KEY last_shown         (last_shown),
  KEY moderation_status  (moderation_status),
  KEY pitch_subject      (subject_id),
  KEY pitch_verb         (verb_id),
  KEY pitch_object       (object_id),
  CONSTRAINT pitch_subject FOREIGN KEY (subject_id) REFERENCES subjects (subject_id),
  CONSTRAINT pitch_verb    FOREIGN KEY (verb_id)    REFERENCES verbs (verb_id),
  CONSTRAINT pitch_object  FOREIGN KEY (object_id)  REFERENCES objects (object_id)
);

CREATE TABLE pitchgame.teams (
  team_id             int           NOT NULL AUTO_INCREMENT,
  when_created        datetime      NOT NULL DEFAULT current_timestamp(),
  token               varchar(40)   NOT NULL,
  use_ct              int           NOT NULL DEFAULT 0,
  PRIMARY KEY (team_id)
);

CREATE TABLE pitchgame.sessions (
  session_id          int           NOT NULL AUTO_INCREMENT,
  when_created        datetime      NOT NULL DEFAULT current_timestamp(),
  when_last_used      datetime      NOT NULL DEFAULT current_timestamp(),
  signature           varchar(100)  DEFAULT NULL COMMENT 'acts as default for pitches.signature',
  team_id             int           DEFAULT NULL,
  ip_address          varchar(40)   DEFAULT NULL COMMENT 'for spam moderation only',
  useragent           varchar(1000) DEFAULT NULL COMMENT 'for spam moderation only',
  cookie_token        varchar(40)   DEFAULT NULL,
  sso_provider        varchar(100)  DEFAULT NULL,
  sso_name            varchar(100)  DEFAULT NULL,
  is_test             boolean       NOT NULL DEFAULT 0,
  PRIMARY KEY            (session_id),
  KEY when_last_used     (when_last_used),
  KEY team               (team_id),
  KEY cookie_token       (cookie_token),
  CONSTRAINT team FOREIGN KEY (team_id) REFERENCES teams (team_id)
);

CREATE TABLE pitchgame.ratings (
  rating_id           int           NOT NULL AUTO_INCREMENT,
  session_id          int           NOT NULL,
  pitch_id            int           NOT NULL,
  rating              tinyint(2)    NOT NULL COMMENT '1 to 4, and -1 means mark as spam',
  when_rated          datetime      NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY            (rating_id),
  UNIQUE KEY no_dupes    (pitch_id, session_id),
  KEY session_rated      (session_id),
  CONSTRAINT pitch_rated   FOREIGN KEY (pitch_id)   REFERENCES pitches (pitch_id),
  CONSTRAINT session_rated FOREIGN KEY (session_id) REFERENCES sessions (session_id)
);

CREATE TABLE pitchgame.suggestions (
  suggestion_id       int           NOT NULL AUTO_INCREMENT,
  session_id          int           NOT NULL,
  subject_id          int           NOT NULL,
  verb_id             int           NOT NULL,
  object_id           int           NOT NULL,
  when_suggested      datetime      NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY            (suggestion_id),
  UNIQUE KEY no_dupes    (session_id, subject_id, verb_id, object_id)
  KEY subject_id         (subject_id),
  KEY verb_id            (verb_id),
  KEY object_id          (object_id),
  KEY session_id         (session_id),
  CONSTRAINT session_id FOREIGN KEY (session_id) REFERENCES sessions (session_id),
  CONSTRAINT subject_id FOREIGN KEY (subject_id) REFERENCES subjects (subject_id),
  CONSTRAINT verb_id    FOREIGN KEY (verb_id)    REFERENCES verbs (verb_id),
  CONSTRAINT object_id  FOREIGN KEY (object_id)b REFERENCES objects (object_id)
);
