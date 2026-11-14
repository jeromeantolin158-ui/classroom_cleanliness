--
-- PostgreSQL database dump
--

\restrict HHB9fCLzyRn1OXlRMzgGCTqmObaHdzBZRWivnOkDunqaq4FJAMZim3uUbeXd4sB

-- Dumped from database version 18.6
-- Dumped by pg_dump version 18.6

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: btree_gist; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS btree_gist WITH SCHEMA public;


--
-- Name: EXTENSION btree_gist; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION btree_gist IS 'support for indexing common datatypes in GiST';


--
-- Name: pgcrypto; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;


--
-- Name: EXTENSION pgcrypto; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION pgcrypto IS 'cryptographic functions';


--
-- Name: timerange; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.timerange AS RANGE (
    subtype = time without time zone,
    multirange_type_name = public.timemultirange
);


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: email_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.email_log (
    log_id integer NOT NULL,
    kind character varying(30) NOT NULL,
    schedule_id integer NOT NULL,
    usage_date date NOT NULL,
    user_id integer,
    to_email character varying(100) NOT NULL,
    status character varying(10) NOT NULL,
    error text,
    sent_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT email_log_status_check CHECK (((status)::text = ANY ((ARRAY['sending'::character varying, 'sent'::character varying, 'failed'::character varying])::text[])))
);


--
-- Name: email_log_log_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.email_log ALTER COLUMN log_id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.email_log_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: login_attempts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.login_attempts (
    attempt_id integer NOT NULL,
    username character varying(50) NOT NULL,
    ip character varying(45) NOT NULL,
    attempted_at timestamp without time zone DEFAULT now() NOT NULL
);


--
-- Name: login_attempts_attempt_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.login_attempts ALTER COLUMN attempt_id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.login_attempts_attempt_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: password_requests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_requests (
    request_id integer NOT NULL,
    user_id integer NOT NULL,
    requested_at timestamp without time zone DEFAULT now() NOT NULL,
    status character varying(10) DEFAULT 'pending'::character varying NOT NULL,
    handled_by integer,
    handled_at timestamp without time zone
);


--
-- Name: password_requests_request_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.password_requests ALTER COLUMN request_id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.password_requests_request_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: ratings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ratings (
    rating_id integer NOT NULL,
    usage_id integer NOT NULL,
    rated_by_section integer NOT NULL,
    score smallint NOT NULL,
    comment text,
    rated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT ratings_score_check CHECK (((score >= 1) AND (score <= 5)))
);


--
-- Name: ratings_rating_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.ratings ALTER COLUMN rating_id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.ratings_rating_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: room_usage; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.room_usage (
    usage_id integer NOT NULL,
    schedule_id integer NOT NULL,
    usage_date date DEFAULT CURRENT_DATE NOT NULL,
    time_in timestamp without time zone,
    time_out timestamp without time zone,
    logged_by integer,
    auto_closed boolean DEFAULT false NOT NULL,
    CONSTRAINT out_after_in CHECK (((time_out IS NULL) OR (time_out > time_in)))
);


--
-- Name: room_usage_usage_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.room_usage ALTER COLUMN usage_id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.room_usage_usage_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: rooms; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rooms (
    room_id integer NOT NULL,
    room_number character varying(20) NOT NULL,
    building character varying(50),
    floor character varying(20)
);


--
-- Name: rooms_room_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.rooms ALTER COLUMN room_id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.rooms_room_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: schedules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.schedules (
    schedule_id integer NOT NULL,
    room_id integer NOT NULL,
    section_id integer NOT NULL,
    day_of_week smallint NOT NULL,
    start_time time without time zone NOT NULL,
    end_time time without time zone NOT NULL,
    valid_from date DEFAULT '2000-01-01'::date NOT NULL,
    valid_to date DEFAULT '9999-12-31'::date NOT NULL,
    subject character varying(100),
    CONSTRAINT schedules_day_of_week_check CHECK (((day_of_week >= 1) AND (day_of_week <= 7))),
    CONSTRAINT valid_dates CHECK ((valid_to >= valid_from)),
    CONSTRAINT valid_times CHECK ((end_time > start_time))
);


--
-- Name: schedules_schedule_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.schedules ALTER COLUMN schedule_id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.schedules_schedule_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: sections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sections (
    section_id integer NOT NULL,
    section_name character varying(50) NOT NULL,
    program character varying(50),
    building character varying(50)
);


--
-- Name: sections_section_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.sections ALTER COLUMN section_id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.sections_section_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    user_id integer NOT NULL,
    username character varying(50) NOT NULL,
    password_hash character varying(255) NOT NULL,
    full_name character varying(100) NOT NULL,
    role character varying(20) DEFAULT 'user'::character varying NOT NULL,
    section_id integer,
    active boolean DEFAULT true NOT NULL,
    must_change boolean DEFAULT false NOT NULL,
    email character varying(100)
);


--
-- Name: users_user_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.users ALTER COLUMN user_id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME public.users_user_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: email_log email_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.email_log
    ADD CONSTRAINT email_log_pkey PRIMARY KEY (log_id);


--
-- Name: login_attempts login_attempts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.login_attempts
    ADD CONSTRAINT login_attempts_pkey PRIMARY KEY (attempt_id);


--
-- Name: schedules no_room_overlap; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schedules
    ADD CONSTRAINT no_room_overlap EXCLUDE USING gist (room_id WITH =, day_of_week WITH =, public.timerange(start_time, end_time) WITH &&, daterange(valid_from, valid_to, '[]'::text) WITH &&);


--
-- Name: schedules no_section_overlap; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schedules
    ADD CONSTRAINT no_section_overlap EXCLUDE USING gist (section_id WITH =, day_of_week WITH =, public.timerange(start_time, end_time) WITH &&, daterange(valid_from, valid_to, '[]'::text) WITH &&);


--
-- Name: room_usage one_log_per_day; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.room_usage
    ADD CONSTRAINT one_log_per_day UNIQUE (schedule_id, usage_date);


--
-- Name: ratings one_rating_per_usage; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ratings
    ADD CONSTRAINT one_rating_per_usage UNIQUE (usage_id);


--
-- Name: password_requests password_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_requests
    ADD CONSTRAINT password_requests_pkey PRIMARY KEY (request_id);


--
-- Name: ratings ratings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ratings
    ADD CONSTRAINT ratings_pkey PRIMARY KEY (rating_id);


--
-- Name: room_usage room_usage_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.room_usage
    ADD CONSTRAINT room_usage_pkey PRIMARY KEY (usage_id);


--
-- Name: rooms rooms_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rooms
    ADD CONSTRAINT rooms_pkey PRIMARY KEY (room_id);


--
-- Name: rooms rooms_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rooms
    ADD CONSTRAINT rooms_unique UNIQUE NULLS NOT DISTINCT (room_number, building);


--
-- Name: schedules schedules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schedules
    ADD CONSTRAINT schedules_pkey PRIMARY KEY (schedule_id);


--
-- Name: sections sections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sections
    ADD CONSTRAINT sections_pkey PRIMARY KEY (section_id);


--
-- Name: sections sections_section_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sections
    ADD CONSTRAINT sections_section_name_key UNIQUE (section_name);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (user_id);


--
-- Name: users users_username_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_username_key UNIQUE (username);


--
-- Name: login_attempts_username_ip_attempted_at_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX login_attempts_username_ip_attempted_at_idx ON public.login_attempts USING btree (username, ip, attempted_at);


--
-- Name: one_email_per_class; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX one_email_per_class ON public.email_log USING btree (kind, schedule_id, usage_date, to_email) WHERE ((status)::text <> 'failed'::text);


--
-- Name: one_pending_request; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX one_pending_request ON public.password_requests USING btree (user_id) WHERE ((status)::text = 'pending'::text);


--
-- Name: users_email_uniq; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX users_email_uniq ON public.users USING btree (lower((email)::text)) WHERE (email IS NOT NULL);


--
-- Name: email_log email_log_schedule_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.email_log
    ADD CONSTRAINT email_log_schedule_id_fkey FOREIGN KEY (schedule_id) REFERENCES public.schedules(schedule_id) ON DELETE CASCADE;


--
-- Name: email_log email_log_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.email_log
    ADD CONSTRAINT email_log_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON DELETE SET NULL;


--
-- Name: password_requests password_requests_handled_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_requests
    ADD CONSTRAINT password_requests_handled_by_fkey FOREIGN KEY (handled_by) REFERENCES public.users(user_id);


--
-- Name: password_requests password_requests_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_requests
    ADD CONSTRAINT password_requests_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id);


--
-- Name: ratings ratings_rated_by_section_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ratings
    ADD CONSTRAINT ratings_rated_by_section_fkey FOREIGN KEY (rated_by_section) REFERENCES public.sections(section_id);


--
-- Name: ratings ratings_usage_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ratings
    ADD CONSTRAINT ratings_usage_id_fkey FOREIGN KEY (usage_id) REFERENCES public.room_usage(usage_id);


--
-- Name: room_usage room_usage_logged_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.room_usage
    ADD CONSTRAINT room_usage_logged_by_fkey FOREIGN KEY (logged_by) REFERENCES public.users(user_id);


--
-- Name: room_usage room_usage_schedule_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.room_usage
    ADD CONSTRAINT room_usage_schedule_id_fkey FOREIGN KEY (schedule_id) REFERENCES public.schedules(schedule_id);


--
-- Name: schedules schedules_room_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schedules
    ADD CONSTRAINT schedules_room_id_fkey FOREIGN KEY (room_id) REFERENCES public.rooms(room_id);


--
-- Name: schedules schedules_section_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.schedules
    ADD CONSTRAINT schedules_section_id_fkey FOREIGN KEY (section_id) REFERENCES public.sections(section_id);


--
-- Name: users users_section_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_section_id_fkey FOREIGN KEY (section_id) REFERENCES public.sections(section_id);


--
-- PostgreSQL database dump complete
--

\unrestrict HHB9fCLzyRn1OXlRMzgGCTqmObaHdzBZRWivnOkDunqaq4FJAMZim3uUbeXd4sB

