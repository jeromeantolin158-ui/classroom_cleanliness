<?php
try {
    $pdo = new PDO(
        "pgsql:host=localhost;port=5432;dbname=classroom_cleanliness",
        "postgres",
        "jeromelumbao@09"
    );
    echo "Connected!";

    foreach ($pdo->query("SELECT * FROM rooms") as $row) {
        echo "<br>Room " . $row['room_number'];
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}