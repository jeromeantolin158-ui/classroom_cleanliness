-- =====================================================================
-- SpotLess / CICI demo data  (run in pgAdmin Query Tool, classroom DB)
-- Safe to run more than once: it skips rows that already exist.
-- Demo password for every NEW section account: Password123
-- =====================================================================

-- OPTIONAL: wipe old TEST data first (uncomment ONLY if you want a clean start).
-- This deletes ratings, room usage and schedules. It keeps rooms, sections and users.
-- DELETE FROM ratings;  DELETE FROM room_usage;  DELETE FROM schedules;

-- 0. Make building names consistent
UPDATE rooms SET building = 'Main building' WHERE lower(building) = 'main building' AND building <> 'Main building';

-- 1. Rooms (12) ---------------------------------------------------------
INSERT INTO rooms (room_number, building, floor)
SELECT v.n, v.b, v.f
FROM (VALUES
  ('101','Main building','1st floor'), ('102','Main building','1st floor'), ('103','Main building','1st floor'),
  ('201','Main building','2nd floor'), ('202','Main building','2nd floor'), ('203','Main building','2nd floor'),
  ('S-101','Science building','1st floor'), ('S-102','Science building','1st floor'),
  ('S-201','Science building','2nd floor'), ('S-202','Science building','2nd floor'),
  ('A-101','Annex','1st floor'), ('A-102','Annex','1st floor')
) v(n, b, f)
WHERE NOT EXISTS (SELECT 1 FROM rooms r WHERE r.room_number = v.n AND r.building IS NOT DISTINCT FROM v.b);

UPDATE rooms SET floor = CASE left(regexp_replace(room_number, '\D', '', 'g'), 1)
                           WHEN '2' THEN '2nd floor' WHEN '3' THEN '3rd floor' ELSE '1st floor' END
WHERE floor IS NULL;

-- 2. Sections (8) -------------------------------------------------------
INSERT INTO sections (section_name, program, building)
VALUES ('BSIT 2-A','BSIT','Main building'), ('BSIT 2-B','BSIT','Main building'), ('BSIT 3-A','BSIT','Main building'),
       ('BSCS 1-A','BSCS','Science building'), ('BSCS 2-A','BSCS','Science building'),
       ('BSBA 1-A','BSBA','Annex'), ('BSBA 2-A','BSBA','Annex'), ('BSED 3-A','BSED','Annex')
ON CONFLICT (section_name) DO UPDATE
   SET building = COALESCE(sections.building, EXCLUDED.building),
       program  = COALESCE(sections.program,  EXCLUDED.program);

-- 3. Section accounts (one per section) --------------------------------
INSERT INTO users (username, password_hash, full_name, email, role, section_id, must_change)
SELECT v.u, '$2y$10$ChYW.AwQw5ayPJn19TNnzeKUjaByEDwMXbxI86yBZGED03a/EIF6W', v.fn, v.u || '@example.com', 'user', s.section_id, FALSE
FROM (VALUES
  ('bsit2a','BSIT 2-A','Maria Santos'),   ('bsit2b','BSIT 2-B','Jose Reyes'),
  ('bsit3a','BSIT 3-A','Angela Cruz'),    ('bscs1a','BSCS 1-A','Mark Villanueva'),
  ('bscs2a','BSCS 2-A','Hannah Lim'),     ('bsba1a','BSBA 1-A','Paolo Garcia'),
  ('bsba2a','BSBA 2-A','Trisha Mendoza'), ('bsed3a','BSED 3-A','Daniel Ramos')
) v(u, sn, fn)
JOIN sections s ON s.section_name = v.sn
ON CONFLICT (username) DO NOTHING;

-- placeholder emails for accounts that have none yet (change them later in Users > Edit)
UPDATE users SET email = username || '@example.com' WHERE email IS NULL;

-- 4. Weekly schedules, 3 classes per section per weekday (Mon-Fri),
--    then 24 days of room usage and ratings. Overlaps are skipped safely.
DO $$
DECLARE
  sec_names text[] := ARRAY['BSIT 2-A','BSIT 2-B','BSIT 3-A','BSCS 1-A','BSCS 2-A','BSBA 1-A','BSBA 2-A','BSED 3-A'];
  room_keys text[] := ARRAY['Main building|101','Main building|102','Main building|103','Main building|201','Main building|202','Main building|203',
                            'Science building|S-101','Science building|S-102','Science building|S-201','Science building|S-202',
                            'Annex|A-101','Annex|A-102'];
  starts   time[] := ARRAY['07:30','09:00','10:30','13:00','14:30','16:00']::time[];
  ends     time[] := ARRAY['09:00','10:30','12:00','14:30','16:00','17:30']::time[];
  subjects text[] := ARRAY['Programming 2','Data Structures','Web Development','Networking Fundamentals','Database Systems','Calculus',
                           'Business Mathematics','Technical Writing','Operating Systems','Human-Computer Interaction','Principles of Marketing','Teaching Methods'];
  sec_ids int[]; room_ids int[]; nS int; nR int;
  s int; d int; k int; slot int; r int; x int;
BEGIN
  SELECT array_agg(s.section_id ORDER BY array_position(sec_names, s.section_name)) INTO sec_ids
    FROM sections s WHERE s.section_name = ANY(sec_names);
  SELECT array_agg(r.room_id ORDER BY array_position(room_keys, r.building || '|' || r.room_number)) INTO room_ids
    FROM rooms r WHERE (r.building || '|' || r.room_number) = ANY(room_keys);
  nS := coalesce(array_length(sec_ids, 1), 0);
  nR := coalesce(array_length(room_ids, 1), 0);
  IF nS < 8 OR nR < 12 THEN
    RAISE EXCEPTION 'Rooms or sections are missing (% sections, % rooms found). Run the whole script again.', nS, nR;
  END IF;

  FOR s IN 0..nS-1 LOOP
    FOR d IN 1..5 LOOP
      FOREACH k IN ARRAY ARRAY[0,2,4] LOOP
        slot := (s + d + k) % 6;
        r := (s + slot + d) % nR;
        x := (s * 3 + d + k / 2) % 12;
        BEGIN
          INSERT INTO schedules (room_id, section_id, day_of_week, start_time, end_time, subject, valid_from, valid_to)
          VALUES (room_ids[r + 1], sec_ids[s + 1], d, starts[slot + 1], ends[slot + 1], subjects[x + 1], CURRENT_DATE - 60, CURRENT_DATE + 60);
        EXCEPTION WHEN exclusion_violation OR unique_violation THEN
          NULL;  -- that room/section was already busy at that time: skip it
        END;
      END LOOP;
    END LOOP;
  END LOOP;

  -- Room usage + ratings are only generated once (skipped if the table already has history)
  IF (SELECT count(*) FROM room_usage) < 50 THEN

  -- Room usage: each class that has already started, a few never timed in
  INSERT INTO room_usage (schedule_id, usage_date, time_in, time_out, logged_by)
  SELECT x.schedule_id, x.dt,
         CASE WHEN x.dt = CURRENT_DATE THEN LEAST(x.dt + x.start_time + make_interval(mins => x.i), localtimestamp - interval '1 minute')
              ELSE x.dt + x.start_time + make_interval(mins => x.i) END,
         CASE WHEN x.dt = CURRENT_DATE AND (x.dt + x.end_time) > localtimestamp THEN NULL
              ELSE x.dt + x.end_time + make_interval(mins => x.o) END,
         (SELECT u.user_id FROM users u WHERE u.section_id = x.section_id AND u.role = 'user' ORDER BY u.user_id LIMIT 1)
  FROM (
    SELECT sc.schedule_id, sc.section_id, sc.start_time, sc.end_time, g.d::date AS dt,
           (floor(random() * 18))::int - 3 AS i,
           -(1 + floor(random() * 10))::int AS o,
           random() AS p
    FROM schedules sc
    JOIN generate_series((CURRENT_DATE - 24)::timestamp, CURRENT_DATE::timestamp, interval '1 day') g(d)
      ON EXTRACT(ISODOW FROM g.d) = sc.day_of_week
    WHERE sc.section_id = ANY(sec_ids)
      AND sc.subject IS NOT NULL                                   -- only the demo schedules
      AND sc.end_time - sc.start_time >= interval '60 minutes'      -- skip very short test classes
      AND g.d::date BETWEEN sc.valid_from AND sc.valid_to
      AND (g.d::date < CURRENT_DATE OR (g.d::date + sc.start_time) < localtimestamp)
  ) x
  WHERE x.p > CASE WHEN x.dt = CURRENT_DATE THEN 0.12 ELSE 0.05 END
  ON CONFLICT (schedule_id, usage_date) DO NOTHING;

  -- Ratings: the next section rates the room the previous section left behind
  INSERT INTO ratings (usage_id, rated_by_section, score, comment, rated_at)
  SELECT q.prev_id, q.next_sec, q.score,
         CASE WHEN q.c1 < 0.15 THEN NULL ELSE
           CASE q.score
             WHEN 5 THEN (ARRAY['Excellent room handoff.','Desks and floor were spotless.','Room was ready for us.','Thank you for cleaning.'])[1 + floor(q.c2 * 4)::int]
             WHEN 4 THEN (ARRAY['Chairs neatly arranged.','Clean overall, a few scraps on the floor.','Board was erased, good job.'])[1 + floor(q.c2 * 3)::int]
             WHEN 3 THEN (ARRAY['Some paper left under the desks.','Board was not fully erased.','Chairs were not put back.'])[1 + floor(q.c2 * 3)::int]
             WHEN 2 THEN (ARRAY['Waste left near the board.','Trash on the floor and desks.'])[1 + floor(q.c2 * 2)::int]
             ELSE 'Room was very messy and needs attention.'
           END
         END,
         LEAST(q.next_in + make_interval(mins => 2 + floor(q.c3 * 8)::int), localtimestamp)
  FROM (
    SELECT z.*,
           CASE WHEN z.c4 < 0.02 THEN 1
                WHEN z.xs < 0.07 THEN 2
                WHEN z.xs < 0.17 THEN 3
                WHEN z.xs < 0.50 THEN 4
                ELSE 5 END AS score
    FROM (
      SELECT p.usage_id AS prev_id, sc2.section_id AS next_sec, r2.time_in AS next_in,
             random() AS c1, random() AS c2, random() AS c3, random() AS c4, random() AS c5,
             random() + CASE p.section_id % 4 WHEN 0 THEN 0.10 WHEN 1 THEN 0.0 WHEN 2 THEN -0.06 ELSE -0.14 END AS xs
      FROM room_usage r2
      JOIN schedules sc2 ON sc2.schedule_id = r2.schedule_id
      JOIN LATERAL (
        SELECT pu.usage_id, pu.time_out, psc.section_id
        FROM room_usage pu JOIN schedules psc ON psc.schedule_id = pu.schedule_id
        WHERE psc.room_id = sc2.room_id AND pu.usage_date = r2.usage_date AND pu.time_in < r2.time_in
        ORDER BY pu.time_in DESC LIMIT 1
      ) p ON TRUE
      WHERE r2.time_in IS NOT NULL AND p.time_out IS NOT NULL
        AND p.section_id <> sc2.section_id
        AND p.time_out <= r2.time_in AND r2.time_in - p.time_out <= interval '2 hours'
        AND sc2.section_id = ANY(sec_ids)
    ) z
    WHERE z.c5 < CASE WHEN CURRENT_DATE = z.next_in::date THEN 0.80 ELSE 0.93 END   -- leave a few unrated
  ) q
  WHERE NOT EXISTS (SELECT 1 FROM ratings x WHERE x.usage_id = q.prev_id);
  END IF;
END $$;

-- 5. One pending "forgot password" request so the bell shows a notification
INSERT INTO password_requests (user_id)
SELECT u.user_id FROM users u
WHERE u.username = 'bsit3a'
  AND NOT EXISTS (SELECT 1 FROM password_requests p WHERE p.user_id = u.user_id AND p.status = 'pending');

-- 6. Quick check
SELECT (SELECT count(*) FROM rooms)      AS rooms,
       (SELECT count(*) FROM sections)   AS sections,
       (SELECT count(*) FROM users)      AS users,
       (SELECT count(*) FROM schedules)  AS schedules,
       (SELECT count(*) FROM room_usage) AS room_usage,
       (SELECT count(*) FROM ratings)    AS ratings;
