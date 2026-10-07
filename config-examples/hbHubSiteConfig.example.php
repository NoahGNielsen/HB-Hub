<?php
/**
 * HB Hub site configuration template.
 *
 * Copy this file into the folder one level above the web root (the folder that
 * contains the uploaded src/ files), rename it to hbHubSiteConfig.php, then fill in the values.
 * The site loads it from exactly that path.
 */
return [
    'footer_email' => 'me@example.com',
    'footer_copyrightYear' => '2026',
    'site_maintenance' => false,    // Default: false | Set to true to not allow users to log in or register, and show a maintenance message instead
    'site_maintenance_message' => 'The site is currently undergoing maintenance. Please check back later.', // Default: 'The site is currently undergoing maintenance. Please check back later.' | Message to show when the site is in maintenance mode
    'site_save_diskspace' => 0, // Default: 0 | 0 is off, Set to 1 to not store files over 5 MB for more than 72 hours, and set to 2 to not allow user uploads at all.

    'user_name_maxSize' => 64, // Default: 64 characters | Don't exceed 64 characters, as the database column is VARCHAR(64)
    'user_description_maxSize' => 255, // Default: 255 characters | Don't exceed 255 characters, as the database column is VARCHAR(255)
    'user_avatar_maxSize' => 5000, // Default: 5 MB | Don't exceed 4 GB, as the database column is LONGBLOB

    'onboarding_avatar_requirements' => '1', // Default: 1 | 0 = required, 1 = optional, 2 = disabled
    'onboarding_password_requirements' => '3', // Default: 3 | 1 = just don't use, 2 = weak, 3 = medium, 4 = strong
    'onboarding_description_requirements' => '1', // Default: 1 | 0 = required (min of 10 characters), 1 = optional, 2 = disabled

    'login_attempts_max' => 5, // Default: 5 | Number of failed login attempts before the user is locked out for a period of time
    'login_attempts_lockout_time' => 15, // Default: 15 minutes
    
    'user_uploads_maxSize' => 5000, // Default: 5 MB | This is different from the avatar max size. This is for everything else. Don't exceed 4 GB, as the database column is LONGBLOB

    'database_credentials' => [ // Database credentials
        'host' => 'your_db_hostname', 
        'name' => 'your_db_name',
        'user' => 'your_db_username',
        'pass' => 'your_db_password',
    ],
    'giphy_gif_integration' => true, // Default: true | Set to false to disable Giphy integration
    'giphy_api_key' => 'your_giphy_api_key', // Required for Giphy integration. Get one at https://developers.giphy.com/
];