<?php
/**
 * HB Hub site configuration template.
 *
 * Copy this file into the folder one level above the web root (the folder that
 * contains the uploaded src/ files), rename it to hbHubSiteConfig.php, then fill in the values.
 * The site loads it from exactly that path.
 */
return [
    'footer_copyrightYear' => '2026',
    'site_maintenance' => false,    // Default: false | Set to true to not allow users to log in or register, and show a maintenance message instead
    'site_maintenance_message' => 'The site is currently undergoing maintenance. Please check back later.', // Default: 'The site is currently undergoing maintenance. Please check back later.' | Message to show when the site is in maintenance mode
    'site_save_diskspace' => 0, // Default: 0 | 0 is off, Set to 1 to not store files over 5 MB for more than 72 hours, and set to 2 to not allow user uploads at all (chat attachments included).
    'attachments_path' => null, // Default: null, a folder called hbHubAttachments next to this file | Where chat attachments are kept on disk, as a full path. Keep it outside the web root; it's made (with a .htaccess that denies everything) the first time it's needed
    'attachments_max_total_bytes' => 9 * 1024 * 1024 * 1024, // Default: 9 GB | Once all chat attachments together take this much disk space, nobody can send new ones until some expire or are deleted

    'user_name_maxSize' => 35, // Default: 35 characters | Anything above 35 is capped to 35
    'user_description_maxSize' => 255, // Default: 255 characters | Don't exceed 255 characters, as the database column is VARCHAR(255)
    'user_avatar_maxSize' => 5000, // Default: 5 MB | Don't exceed 4 GB, as the database column is LONGBLOB
    'banned_avatar_similarity' => 97.5, // Default: 97.5 | How alike (in percent) an uploaded profile picture has to look to a banned one to get the account banned. Kept between 90 and 100: lower catches more edited copies, but also bans people for pictures that only look a bit alike

    'onboarding_avatar_requirements' => '1', // Default: 1 | 0 = required, 1 = optional, 2 = disabled
    'onboarding_password_requirements' => '3', // Default: 3 | 1 = just don't use, 2 = weak, 3 = medium, 4 = strong
    'onboarding_description_requirements' => '1', // Default: 1 | 0 = required (min of 10 characters), 1 = optional, 2 = disabled
    'onboarding_accounts_per_ip_hour' => 20, // Default: 20 | How many accounts can be made from one IP address in an hour. A school network shares one address, so leave room for a class signing up together

    'login_attempts_max' => 5, // Default: 5 | Number of failed login attempts before the user is locked out for a period of time. A device the account has logged in on before has its own count, so others can't lock the owner out
    'login_attempts_lockout_time' => 15, // Default: 15 minutes
    
    'user_uploads_maxSize' => 5000, // Default: 5 MB | This is different from the avatar max size. This is for everything else. Don't exceed 4 GB, as the database column is LONGBLOB

    'database_credentials' => [ // Database credentials
        'host' => 'your_db_hostname', 
        'name' => 'your_db_name',
        'user' => 'your_db_username',
        'pass' => 'your_db_password',
    ],
    'casino_access' => 1, // Default: 1 | Who can open the casino (the Gambling pages): 0 = nobody, 1 = everyone, 2 = only admins (userRole 2)
    'games_access' => 1, // Default: 1 | Who can open the Games pages (Matador, Chess, UNO, Wordle, Ore Mining Inc.): 0 = nobody, 1 = everyone, 2 = only admins (userRole 2)

    'giphy_gif_integration' => true, // Default: true | Set to false to disable Giphy integration
    'giphy_api_key' => 'your_giphy_api_key', // Required for Giphy integration. Get one at https://developers.giphy.com/
];