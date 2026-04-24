<?php

namespace ContinuumUniverses\ContinuumProfile\UserProfile;

// Keep the avatar prefix tied to the current wiki database.
// The rest of the UserProfile defaults now live in extension.json.
$wgAvatarKey = $GLOBALS['wgDBname'];
