<?php
// Redirect back to the main page if someone browses /data/ directly
header('Location: ../index.php', true, 302);
exit;
