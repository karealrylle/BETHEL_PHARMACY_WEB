<?php

$host = "localhost"; // Server host

$user = "root"; // MySQL username

$pass = ""; // MySQL password (leave blank if none)

$db = "bethel_pharmacy"; // Database name

 

// Create connection

$conn = new mysqli($host, $user, $pass, $db);

 

// Check connection

if ($conn->connect_error) {

die("Connection failed: " . $conn->connect_error);

}

// Function to close connection
function closeConnection() {
    global $conn;
    if ($conn) {
        $conn->close();
    }
}

?>
