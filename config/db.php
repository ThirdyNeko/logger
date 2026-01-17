<?php
$servername = "localhost";
$username = "root";
$password = "Bimbim101602";
$database = "qa_logger";

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

function qa_db(): mysqli {
    global $conn;
    return $conn;
}
