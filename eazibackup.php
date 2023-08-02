<?php
/**
 * Plugin Name: eazibackup
 * Description: eazibackup is a wordpress backup plugin.
 * Version: 1.0.0-pre1
 * Author: Zissis Sekros
 * Text Domain: eazibackup creates a backup of your site. Download the backup file or use our Premium feature to auto-store a zero-knowledge copy, offsite.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Activation Hook
register_activation_hook(__FILE__, 'eazibackup_activate');

// Deactivation Hook
register_deactivation_hook(__FILE__, 'eazibackup_deactivate');

// Activate
function eazibackup_activate() {
}

// Deactivate
function eazibackup_deactivate() {
}

// Add Admin Menu Pages
add_action('admin_menu', 'eazibackup_add_admin_menus');

function eazibackup_add_admin_menus() {
    // Main Menu
    add_menu_page(
        'eazibackup',
        'eazibackup',
        'manage_options',
        'eazibackup-dashboard',
        'eazibackup_render_dashboard' // Callback function to render the dashboard page (blank)
    );

    // Submenu page for Schedule
    add_submenu_page(
        'eazibackup-dashboard',
        'Schedule',
        'Schedule',
        'manage_options',
        'eazibackup-schedule',
        'eazibackup_render_schedule_page' // Callback function to render the Schedule page
    );

    // Submenu page for Premium
    add_submenu_page(
        'eazibackup-dashboard',
        'Premium',
        'Premium',
        'manage_options',
        'eazibackup-premium',
        'eazibackup_render_premium_page' // Callback function to render the Premium page
    );
}

// Add a dashboard widget to display the next scheduled run
function eazibackup_add_dashboard_widget() {
    wp_add_dashboard_widget(
        'eazibackup_next_run_widget',
        'Next Scheduled eazibackup',
        'eazibackup_next_scheduled_run'
    );
}
add_action('wp_dashboard_setup', 'eazibackup_add_dashboard_widget');

function eazibackup_render_dashboard() {
    ?>
    <div class="wrap">
        <h1>eazibackup Dashboard</h1>
        <?php
        // Display the next scheduled run widget
        eazibackup_next_scheduled_run();
        ?>
    </div>
    <?php
    // Check if the current user has the capability to manage options (i.e., an admin or a user with the necessary capability)
    if (!current_user_can('manage_options')) {
        return; // Show nothing if the user doesn't have the required capability
    }

    $backup_folder = dirname(__FILE__) . '/backups/';

    // Get a list of .tgz files in the backup folder
    $backup_files = glob($backup_folder . '*.tgz') + glob($backup_folder . '*.tgz.enc');

    if (empty($backup_files)) {
        echo 'No backup files found.';
        return;
    }

    // Output the list of downloadable backup files
    echo '<h2>Backup Files</h2>';
    echo '<ul>';
    foreach ($backup_files as $backup_file) {
        $file_name = basename($backup_file);
        $file_url = content_url('backups/' . $file_name);
        $file_size = filesize($backup_file);
        $formatted_size = size_format($file_size);
        echo '<li>';
        echo '<a href="' . esc_url($file_url) . '">' . esc_html($file_name) . '</a>';
        echo ' (size: ' . $formatted_size . ' - md5: '.md5_file($backup_file).') | '; // Add a separator
        echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=restore_eazibackup&file=' . $file_name), 'restore_eazibackup')) . '" onclick="return confirm(\'Are you sure you want to restore this backup?\')">Restore</a> |';
        echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=delete_eazibackup&file=' . $file_name), 'delete_eazibackup')) . '" onclick="return confirm(\'Are you sure you want to delete this backup?\')">Delete</a>';
        echo '</li>';
    }
    echo '</ul>';

    // Output the list of remotely stored backup files - premium feature
    echo '<br><h2>Remote Backup Files</h2>';
    $premiumkey = get_option('eazibackup_premiumkey');
    if ( $premiumkey !== "" && validate_premium($premiumkey)) {
        // API URL
        $api_url = 'https://my-app.gr/eazibackup/listapi.php';
        $post_data = array(
            'token' => $premiumkey,
            'site' => explode('/',home_url())[2],
        );
        // Initialize cURL session
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            error_log('cURL error: ' . curl_error($ch));
            curl_close($ch);
            return;
        }
        curl_close($ch);
        // Parse the JSON response
        $json_data = json_decode($response, true);
        if ($json_data === null) {
            error_log('JSON parsing error: ' . json_last_error_msg());
            return;
         }
        // Check if the 'msg' key exists and has the expected value
        if (isset($json_data['files'])) {
            echo '<ul>';
            foreach ($json_data['files'] as $file) {
                echo '<li>' . $file['filename'] . ' - ' . $file['md5'] . '</li>';
            }
            echo '</ul>';
        } else {
            echo('Invalid premium key.');
        }
    } else {
        echo('No premium key defined.');
    }
}

// Schedule Page Rendering Function
function eazibackup_render_schedule_page() {
    $schedule = get_option('eazibackup_schedule');

    // Get the saved schedule data for each day and time
    $schedule_data = (is_array($schedule) && !empty($schedule)) ? $schedule : array();

    // Get the expected secret
    $expected_secret = isset($schedule_data['expected_secret']) ? esc_attr($schedule_data['expected_secret']) : '';

    $days_of_week = array(
        'monday'    => 'Monday',
        'tuesday'   => 'Tuesday',
        'wednesday' => 'Wednesday',
        'thursday'  => 'Thursday',
        'friday'    => 'Friday',
        'saturday'  => 'Saturday',
        'sunday'    => 'Sunday',
    );

    ?>
    <div class="wrap">
        <h1>Schedule Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('eazibackup_schedule_options_group');
            do_settings_sections('eazibackup-schedule');
            submit_button();
            ?>
            <input type="submit" name="eazibackup_run_backup" class="button button-primary" value="Run now">
        </form>

    </div>
    <?php
}

// Schedule Field Callback
function eazibackup_schedule_callback() {
    $schedule = get_option('eazibackup_schedule');

    $backup_active = isset($schedule['backup_active']) && $schedule['backup_active'] === 'on' ? 'on' : 'off';
    ?>
    <label>Backup Status:<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
        <input type="checkbox" name="eazibackup_schedule[backup_active]" <?php checked($backup_active, 'on'); ?>> Activate
    </label><br><br>
    <?php
    
    // Get the saved schedule data for each day and time
    $schedule_data = (is_array($schedule) && !empty($schedule)) ? $schedule : array();

    $days_of_week = array(
        'monday'    => 'Monday',
        'tuesday'   => 'Tuesday',
        'wednesday' => 'Wednesday',
        'thursday'  => 'Thursday',
        'friday'    => 'Friday',
        'saturday'  => 'Saturday',
        'sunday'    => 'Sunday',
    );

    echo '<label>Days of week:</label><br>';
    foreach ($days_of_week as $day_key => $day_label) {
        $checked = isset($schedule_data[$day_key]) && $schedule_data[$day_key] === 'on' ? 'checked' : '';
        echo '<label>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="checkbox" name="eazibackup_schedule[' . $day_key . ']" value="on"' . $checked . '> ' . $day_label . '</label><br>';
    }
    echo '<br>';

    // Time input
    $time = isset($schedule_data['time']) ? esc_attr($schedule_data['time']) : '';
    echo '<label>Time:<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <input type="time" name="eazibackup_schedule[time]" value="' . $time . '"></label><br><br>';

    // Versions input
    $versions = isset($schedule_data['versions']) ? intval($schedule_data['versions']) : 1;
    echo '<label>Versions:<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <input type="number" name="eazibackup_schedule[versions]" min="1" max="7" value="' . $versions . '"></label><br><br>';

    // Secret input
    $secret = isset($schedule_data['secret']) ? esc_attr($schedule_data['secret']) : '';
    echo '<label>Expected Secret:<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <input type="text" name="eazibackup_schedule[secret]" value="' . $secret . '"></label><br>'.
            '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Use this secret to execute a backup job externaly.<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; (ie by calling '.home_url().'/eazibackup-backup?secure=secret)<br><br>';

}

function eazibackup_schedule_section_callback() {
}


// Schedule Sanitize Callback
function eazibackup_schedule_sanitize($input) {
    // Ensure the input is an array
    $sanitized_input = is_array($input) ? $input : array();

    // Sanitize and validate each day's value
    $days_of_week = array('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday');
    foreach ($days_of_week as $day) {
        $sanitized_input[$day] = isset($input[$day]) && $input[$day] === 'on' ? 'on' : 'off';
    }

    // Sanitize the time input
    $sanitized_input['time'] = isset($input['time']) ? sanitize_text_field($input['time']) : '';

    // Sanitize the versions input
    $sanitized_input['versions'] = isset($input['versions']) ? intval($input['versions']) : 1;
    $sanitized_input['versions'] = max(1, min(7, $sanitized_input['versions'])); // Ensure it is between 1 and 7
    
    // Sanitize the backup_active input
    $sanitized_input['backup_active'] = isset($input['backup_active']) && $input['backup_active'] === 'on' ? 'on' : 'off';

    // Sanitize the expected secret
    $sanitized_input['secret'] = isset($input['secret']) ? sanitize_text_field($input['secret']) : '';

    return $sanitized_input;
}

// Premium Page Rendering Function
function eazibackup_render_premium_page() {
    $premiumkey = get_option('eazibackup_premiumkey');
    ?>
    <div class="wrap">
        <h1>Premium Settings</h1>
        <p>
        eazibackup premium offers automatic, zero-knowledge, remote storage for your backups.<br />
        <!-- 
        Register at <a href=https://my-apps.gr/eazibackup/register.php target=_blank>https://my-apps.gr/eazibackup/register.php</a> to obtain a premium key and enable this feature.<br /><br />
        -->
        Premium is under development. You can gain early access for free, until we go live.<br />
        We have limited spots available.<br /><br />
        To obrain a premium account contact: <b>eazibackup</b><i>@</i><b>my-app.gr</b>.<br /><br />
        <b>NOTE:</b> Having a non blank premium key below will result remote validation calls.<br>If the key is valid, your backups will be sent to a remote storage location.<br><br>
        <b>NOTE:</b> <b> *** MAKE SURE TO STORE YOUR ENCRYPTION KEY ***</b><br>You will not be able to restore these files in case of a disaster.<br>
        </p>
        <br />
        <form method="post" action="options.php">
            <?php
            settings_fields('eazibackup_premium_options_group');
            do_settings_sections('eazibackup-premium');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Premium Key Field Callback
function eazibackup_premiumkey_callback() {
    $premiumkey = get_option('eazibackup_premiumkey');
    echo '<input type="text" name="eazibackup_premiumkey" value="' . esc_attr($premiumkey) . '" size="40">';
}

// Premium Key Sanitize Callback
function eazibackup_premiumkey_sanitize($input) {
    // Remove any characters that are not English letters, numbers, or dashes
    $sanitized_input = preg_replace('/[^a-zA-Z0-9\-]/', '', $input);
    if ($sanitized_input !== $input) {
        $sanitized_input = get_option('eazibackup_premiumkey');
    }
    // Validate the premium key with service
    $premium_key = get_option('eazibackup_premiumkey');
    if ( validate_premium($sanitized_input) ) {
        return $sanitized_input;
    } else {
        return "";
    }
}

// Premium Key Section Callback (if needed)
function eazibackup_premiumkey_section_callback() {
    // Add any section-specific content here (if needed)
}

function eazibackup_premium_section_callback() {
}

// Encryption Key Field Callback
function eazibackup_encryptionkey_callback() {
    $encryptionkey = get_option('eazibackup_encryptionkey');
    echo '<input type="text" name="eazibackup_encryptionkey" value="' . esc_attr($encryptionkey) . '" size="40">';
}

// Encryption Key Sanitize Callback
function eazibackup_encryptionkey_sanitize($input) {
    // Remove any characters that are not English letters, numbers, or dashes
    $sanitized_input = preg_replace('/[^a-zA-Z0-9\-]/', '', $input);
    if ($sanitized_input !== $input) {
        $sanitized_input = get_option('eazibackup_premiumkey');
    }
    // Validate the premium key with service
    $premium_key = get_option('eazibackup_premiumkey');
    if ( validate_premium($premium_key) ) {
        if (empty($sanitized_input)) {
            $sanitized_input=md5(time());
        }
        return $sanitized_input;
    } else {
        return "";
    }
}

// Register Settings
add_action('admin_init', 'eazibackup_register_schedule_settings');
add_action('admin_init', 'eazibackup_register_premium_settings');

function eazibackup_register_schedule_settings() {
    // Schedule Settings
    add_settings_section(
        'eazibackup_schedule_section',
        'Schedule Settings',
        'eazibackup_schedule_section_callback',
        'eazibackup-schedule'
    );

    add_settings_field(
        'eazibackup_schedule',
        'Schedule',
        'eazibackup_schedule_callback',
        'eazibackup-schedule',
        'eazibackup_schedule_section'
    );

    register_setting(
        'eazibackup_schedule_options_group',
        'eazibackup_schedule',
        'eazibackup_schedule_sanitize'
    );

    // Check if the "RUN NOW" button is clicked
    if (isset($_POST['eazibackup_run_backup'])) {
        // Run the backup immediately
        eazibackup_run_scheduled_backup();

        // Add a success message or do any other action after running the backup
        add_settings_error('eazibackup_messages', 'eazibackup_run_backup', 'Backup has been executed.', 'updated');
    }
}

function eazibackup_register_premium_settings() {
    // Premium Settings
    add_settings_section(
        'eazibackup_premium_section',
        'Premium Settings',
        'eazibackup_premium_section_callback',
        'eazibackup-premium'
    );

    add_settings_field(
        'eazibackup_premiumkey',
        'Premium Key',
        'eazibackup_premiumkey_callback',
        'eazibackup-premium',
        'eazibackup_premium_section'
    );

    add_settings_field(
        'eazibackup_encryptionkey',
        'Encryption Key',
        'eazibackup_encryptionkey_callback',
        'eazibackup-premium',
        'eazibackup_premium_section'
    );

    register_setting(
        'eazibackup_premium_options_group',
        'eazibackup_premiumkey',
        'eazibackup_premiumkey_sanitize'
    );

    register_setting(
        'eazibackup_premium_options_group',
        'eazibackup_encryptionkey',
        'eazibackup_encryptionkey_sanitize'
    );
}

// Check for an active schedule and get the next scheduled execution time
function eazibackup_get_next_scheduled_execution() {
    $schedule = get_option('eazibackup_schedule');
    $backup_active = isset($schedule['backup_active']) && $schedule['backup_active'] === 'on';

    // Check if backup is active
    if (!$backup_active) {
        return false;
    }

    // Get the scheduled time
    $scheduled_time = isset($schedule['time']) ? $schedule['time'] : '00:00';

    // Get the scheduled days of the week
    $scheduled_days = array();
    $days_of_week = array('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday');
    foreach ($days_of_week as $day) {
        if (isset($schedule[$day]) && $schedule[$day] === 'on') {
            $scheduled_days[] = $day;
        }
    }

    // Check for the next scheduled execution time
    $current_time = time();
    $next_execution = null;

    foreach ($scheduled_days as $day) {
        $next_timestamp = strtotime('next ' . ucfirst($day) . ' ' . $scheduled_time);
        if ($next_timestamp > $current_time && (!$next_execution || $next_timestamp < $next_execution)) {
            $next_execution = $next_timestamp;
        }
    }

    return $next_execution;
}

// Helper function to get the next scheduled day based on the schedule settings
function eazibackup_get_next_scheduled_day($schedule) {
    $days_of_week = array(
        'monday'    => 'Monday',
        'tuesday'   => 'Tuesday',
        'wednesday' => 'Wednesday',
        'thursday'  => 'Thursday',
        'friday'    => 'Friday',
        'saturday'  => 'Saturday',
        'sunday'    => 'Sunday',
    );

    $current_day = strtolower(date('l')); // Get the lowercase name of the current day
    $schedule_days = array_keys($days_of_week);

    // Find the index of the current day in the schedule_days array
    $current_index = array_search($current_day, $schedule_days);

    // Loop through the schedule days starting from the next day after the current day
    for ($i = 1; $i <= count($schedule_days); $i++) {
        $next_index = ($current_index + $i) % count($schedule_days); // Wrap around the array
        $next_day = $schedule_days[$next_index];

        if ($schedule[$next_day] === 'on') {
            return $days_of_week[$next_day];
        }
    }

    // If no day is found, return the current day as the fallback
    return $days_of_week[$current_day];
}

// Function to display the date and time of the next scheduled run
function eazibackup_next_scheduled_run() {
    $next_execution_time = eazibackup_get_next_scheduled_execution();
    if ($next_execution_time) {
        // Convert the timestamp to a human-readable format, e.g., using date()
        $formatted_next_execution = date('Y-m-d h:i A', $next_execution_time);
        echo 'Next scheduled eazibackup execution: ' . $formatted_next_execution;
    } else {
        echo 'eazibackup is not scheduled to run.';
    }
}

function eazibackup_get_database_credentials() {
    // Check if environment variables are defined for the database credentials
    if (
        getenv('DB_HOST') &&
        getenv('DB_NAME') &&
        getenv('DB_USER') &&
        getenv('DB_PASSWORD')
    ) {
        $db_credentials = array(
            'host'     => getenv('DB_HOST'),
            'database' => getenv('DB_NAME'),
            'user'     => getenv('DB_USER'),
            'password' => getenv('DB_PASSWORD'),
        );

        return $db_credentials;
    }

    // If environment variables are not defined, try to get them from wp-config.php
    if (file_exists(ABSPATH . 'wp-config.php')) {
        // Include wp-config.php to access its contents
        include_once ABSPATH . 'wp-config.php';

        // Get the database credentials from the wp-config.php constants
        if (
            defined('DB_HOST') &&
            defined('DB_NAME') &&
            defined('DB_USER') &&
            defined('DB_PASSWORD')
        ) {
            $db_credentials = array(
                'host'     => DB_HOST,
                'database' => DB_NAME,
                'user'     => DB_USER,
                'password' => DB_PASSWORD,
            );

            return $db_credentials;
        }
    }

    // If credentials are not found, return false or handle the error accordingly
    return false;
}

function eazibackup_delete_old_files() {
    $backup_directory = dirname(__FILE__) . '/backups/'; // Replace with the actual path to the backup directory
    $schedule = get_option('eazibackup_schedule');

    // Get the list of backup files in the directory
    $backup_files = glob($backup_directory . 'eazibackup-*.tgz') + glob($backup_directory . 'eazibackup-*.tgz.enc');

    // Sort the files in descending order based on their names (latest dates first)
    rsort($backup_files);

    // Keep the first two files (latest dates)
    $files_to_keep = array_slice($backup_files, 0, $schedule['versions']);

    // Loop through the remaining files and delete them
    foreach ($backup_files as $file) {
        if (!in_array($file, $files_to_keep)) {
            // You may want to add checks here to ensure you're only deleting the intended files
            unlink($file);
        }
    }
}

// Callback function to perform the scheduled backup task
function eazibackup_run_scheduled_backup() {
    // Get the database credentials
    $db_credentials = eazibackup_get_database_credentials();

    // Check if backup is active before proceeding
//    $backup_active = isset($schedule['backup_active']) && $schedule['backup_active'] === 'on';
//    if (!$backup_active) {
//        return;
//    }
    
    if (!$db_credentials) {
        // Handle the case when credentials are not found (e.g., log an error, display a message)
        return;
    }

    // Use the $db_credentials array to run your custom mysqli commands
    $connection = new mysqli($db_credentials['host'], $db_credentials['user'], $db_credentials['password'], $db_credentials['database']);

    // Check for mysqli connection errors
    if ($connection->connect_error) {
        // Handle the connection error (e.g., log an error, display a message)
        return;
    }


    $batchSize = 10000; // Set the batch size as per your requirements

    // Create backup directory if not exists
    $backup_directory = dirname(__FILE__) . '/backups/';
    if (!is_dir($backup_directory)) {
        $permissions = 0755;
        mkdir($backup_directory, $permissions);
    }

    $timestamp = date('YmdHi');

    // Open the dump file for writing
    $file_path = $backup_directory . 'database-'.$timestamp.'.sql';
    $file_handle = fopen($file_path, 'w');
    $backup_file = dirname(__FILE__) . '/backups/eazibackup-'.$timestamp.'.tgz';
    
    // Start the transaction
    $connection->begin_transaction();

    try {
        // Get the list of tables in the database
        $tables = [];
        $result = $connection->query("SHOW TABLES");
        while ($row = $result->fetch_row()) {
            $tables[] = $row[0];
        }

        // Start the output buffer to store the dump content
        $output = '';

        // Loop through each table and generate the table structure and data
        foreach ($tables as $table) {
            // Table structure
            $result = $connection->query("SHOW CREATE TABLE `$table`");
            $row = $result->fetch_row();
            fwrite($file_handle, $row[1] . ";\n\n");

            // Table data
            $offset = 0;
            do {
                $query = "SELECT * FROM `$table` LIMIT $offset, $batchSize";
                $result = $connection->query($query);
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        $columns = implode('`, `', array_keys($row));
                        $values = implode("', '", array_map([$connection, 'real_escape_string'], array_values($row)));
                        fwrite($file_handle, "INSERT INTO `$table` (`$columns`) VALUES ('$values');\n");
                    }
                    // Increment the offset to fetch the next batch
                    $offset += $batchSize;
                }
            } while ($result->num_rows > 0);
            fwrite($file_handle, "\n");
        }

        // Fetch routines (stored procedures and functions)
        $result = $connection->query("SHOW PROCEDURE STATUS WHERE Db = '".$db_credentials['database']."'");
        while ($row = $result->fetch_assoc()) {
            $routine_name = $row['Name'];
            $routine_type = $row['Type'];
            $result2 = $connection->query("SHOW CREATE $routine_type $routine_name");
            $row2 = $result2->fetch_row();
            fwrite($file_handle, $row2[2] . ";\n\n");
        }

        // Fetch triggers
        $result = $connection->query("SHOW TRIGGERS");
        while ($row = $result->fetch_assoc()) {
            $trigger_name = $row['Trigger'];
            $table_name = $row['Table'];
            $result2 = $connection->query("SHOW CREATE TRIGGER `$trigger_name`");
            $row2 = $result2->fetch_row();
            fwrite($file_handle, $row2[2] . ";\n\n");
        }

        // Commit the transaction if everything is successful
        $connection->commit();

        error_log("Database dump completed and saved to: $file_path");
    } catch (Exception $e) {
        // Rollback the transaction if an error occurs
        $connection->rollback();

        // Handle the error appropriately
        error_log("Error during database dump: " . $e->getMessage());
    }

    fclose($file_handle);
    // Close the database connection
    $connection->close();
    $plugindir=basename(dirname(__FILE__));
    $output = shell_exec('tar czvf '.$backup_file.' --exclude="./wp-content/plugins/'.$plugindir.'/backups/*tgz.enc" --exclude="./wp-content/plugins/'.$plugindir.'/backups/*tgz" -C '.ABSPATH.' .');
    unlink($file_path);

    // Get the premium key and encryption key settings
    $premium_key = get_option('eazibackup_premiumkey');
    $encryption_key = get_option('eazibackup_encryptionkey');

    // Check if the encryption key is not empty
    if (empty($encryption_key) || !validate_premium($premium_key)) {
        return;
    }
    
    $encryption_key = "key-" . $encryption_key;
    
    // Encrypt the backup file using OpenSSL and the encryption key
    $input_file = $backup_file; // Replace with the actual path to the backup file
    $output_file = $backup_file.'.enc'; // Replace with the desired path for the encrypted backup

    $encryption_method = 'AES-256-CBC';
    $iv_length = openssl_cipher_iv_length($encryption_method);
    $iv = openssl_random_pseudo_bytes($iv_length);

    $encrypted_data = openssl_encrypt(file_get_contents($input_file), $encryption_method, $encryption_key, OPENSSL_RAW_DATA, $iv);
    $encrypted_data_with_iv = $iv . $encrypted_data;

    file_put_contents($output_file, $encrypted_data_with_iv);
    unlink($input_file);
    
    // Call the function to delete old files
    eazibackup_delete_old_files();
    
    // Push to remote storage if premium key allows it.
    $serverUrl = 'https://my-app.gr/eazibackup/upload.php';
    $preSharedToken = $premium_key;
    $filePath = $output_file;
    $chunkSize = 1024 * 1024 * 8;

    $fileHandle = fopen($filePath, 'rb');
    if (!$fileHandle) {
        die('Failed to open the file.');
    }
    $fileSize = filesize($filePath);
    $start = 0;
    $end = min($chunkSize, $fileSize);
    $response = '';
    while ($start < $fileSize) {
        // Read the chunk from the file
        $chunk = fread($fileHandle, $end - $start);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $serverUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'security_token' => $preSharedToken,
            'site' => home_url(),
            'fileName' => $filePath,
            'fileSize' => filesize($filePath),
            'fileContent' => base64_encode($chunk), // Send the file content as a base64-encoded string
            'start' => $start,
            'end' => $end,
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            error_log('cURL Error: ' . curl_error($ch) . PHP_EOL);
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) {
            error_log('Upload failed with HTTP status code: ' . $httpCode . PHP_EOL);
        }
        $start = $end;
        $end = min($end + $chunkSize, $fileSize);
    }
    $md5sum = md5_file($filePath);
    $resp=json_decode($response,true);
    if ($md5sum !== $resp['md5']) {
        error_log('Upload of eazibackup failed. Corruption detected.'.PHP_EOL);
    } else {
        error_log('File uploaded successfully (md5:'.$md5sum. ')!' . PHP_EOL);
    }
    fclose($fileHandle);
}
add_action('eazibackup_scheduled_backup_event', 'eazibackup_run_scheduled_backup', 10, 2);

// Schedule the cron job
function eazibackup_schedule_cron_job() {
    $schedule = get_option('eazibackup_schedule');
    $backup_active = isset($schedule['backup_active']) && $schedule['backup_active'] === 'on';

    // Check if backup is active before scheduling the cron job
    if (!$backup_active) {
        $timestamp = wp_next_scheduled('eazibackup_scheduled_backup_event');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'eazibackup_scheduled_backup_event');
        }
        return;
    }

    // Get the scheduled time
    $scheduled_time = isset($schedule['time']) ? $schedule['time'] : '00:00';

    // Get the scheduled days of the week
    $scheduled_days = array();
    $days_of_week = array('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday');
    foreach ($days_of_week as $day) {
        if (isset($schedule[$day]) && $schedule[$day] === 'on') {
            $scheduled_days[] = $day;
        }
    }

    // Schedule the cron job for each selected day
    foreach ($scheduled_days as $day) {
        $timestamp = strtotime('next ' . ucfirst($day) . ' ' . $scheduled_time);
        if ($timestamp) {
            wp_schedule_single_event($timestamp, 'eazibackup_scheduled_backup_event', array($day, $scheduled_time));
        }
    }
}
add_action('init', 'eazibackup_schedule_cron_job');

// Add a custom endpoint to trigger the backup
function eazibackup_register_backup_endpoint() {
    add_rewrite_endpoint('eazibackup-backup', EP_ROOT);
}
add_action('init', 'eazibackup_register_backup_endpoint');

// Handle the backup request
function eazibackup_handle_backup_request() {
    // Get the secret from the query parameter
    if (!isset($_GET['secret'])) {  // add uri check
        return;
    }
    $secret = isset($_GET['secret']) ? $_GET['secret'] : '';

    // Get the expected secret from the schedule options
    $schedule = get_option('eazibackup_schedule');
    $expected_secret = isset($schedule['secret']) ? $schedule['secret'] : '';

    // Validate the secret
    if ($secret !== $expected_secret) {
        // Invalid secret, return an error response
        http_response_code(403); // Forbidden status code
        error_log('Cron backup request - Authentication failed.');
        exit;
    }

    // Run the scheduled backup function
    eazibackup_run_scheduled_backup();

    // Send a response indicating the backup process has started
    echo 'Backup process initiated.';
    exit;
}
add_action('template_redirect', 'eazibackup_handle_backup_request');

// Restore the site from backup
function restoreDatabaseTables($dbHost, $dbUsername, $dbPassword, $dbName, $filePath){
    // Connect & select the database
    $db = new mysqli($dbHost, $dbUsername, $dbPassword, $dbName); 

    // Temporary variable, used to store current query
    $templine = '';
    
    // Read in entire file
    $lines = file($filePath);
    
    $error = '';
    
    // Loop through each line
    foreach ($lines as $line){
        // Skip it if it's a comment
        if(substr($line, 0, 2) == '--' || $line == ''){
            continue;
        }
        
        // Add this line to the current segment
        $templine .= $line;
        
        // If it has a semicolon at the end, it's the end of the query
        if (substr(trim($line), -1, 1) == ';'){
            // Perform the query
            if(!$db->query($templine)){
                $error .= 'Error performing query "<b>' . $templine . '</b>": ' . $db->error . '<br /><br />';
            }
            
            // Reset temp variable to empty
            $templine = '';
        }
    }
    return !empty($error)?$error:true;
}

// Function to handle the restoration process
function eazibackup_restore() {
    // Check the nonce for security
    $nonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
    if (!wp_verify_nonce($nonce, 'restore_eazibackup')) {
        die('Security check failed');
    }

    // Get the file name from the URL parameter
    $file_name = isset($_GET['file']) ? sanitize_file_name($_GET['file']) : '';
    // BUG fix for sanitize_file_name
    $file_name = str_replace('.tgz_.enc','.tgz.enc',$file_name);
    $backup_file = dirname(__FILE__) . '/backups/' . $file_name;

    // Rest of the restoration logic
    // Implement the restoration process here
    if (str_ends_with($backup_file, '.tgz')) {
        $output = shell_exec('tar xzvf '.$backup_file.' -C '.ABSPATH);
    }
    if (str_ends_with($backup_file, '.tgz.enc')) {
        $input_file = $backup_file;
        $output_file = $backup_file . ".tgz";
        $encryption_key = "key-" . get_option('eazibackup_encryptionkey');
        $encryption_method = 'AES-256-CBC';
        $iv_length = openssl_cipher_iv_length($encryption_method);
        $encrypted_data_with_iv = file_get_contents($input_file);
        $iv = substr($encrypted_data_with_iv, 0, $iv_length);
        $encrypted_data = substr($encrypted_data_with_iv, $iv_length);
        $decrypted_data = openssl_decrypt($encrypted_data, $encryption_method, $encryption_key, OPENSSL_RAW_DATA, $iv);
        file_put_contents($output_file, $decrypted_data);
        $output = shell_exec('tar xzvf '.$output_file.' -C '.ABSPATH);
        unlink($output_file);
    }
    //$connection = new mysqli($db_credentials['host'], $db_credentials['user'], $db_credentials['password'], $db_credentials['database']);
    $db_credentials = eazibackup_get_database_credentials();
    $dump_file = $backup_file;
    $dump_file = str_replace('tgz', 'sql', $dump_file); 
    restoreDatabaseTables($db_credentials['host'], $db_credentials['user'], $db_credentials['password'], $db_credentials['database'], $dump_file);

    // Redirect back to the dashboard after the restoration process
    wp_safe_redirect(admin_url('admin.php?page=eazibackup-dashboard'));
    exit;
}

function eazibackup_delete() {
    // Check the nonce for security
    $nonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
    if (!wp_verify_nonce($nonce, 'delete_eazibackup')) {
        die('Security check failed');
    }

    // Get the file name from the URL parameter
    $file_name = isset($_GET['file']) ? sanitize_file_name($_GET['file']) : '';
    // BUG fix for sanitize_file_name
    $file_name = str_replace('.tgz_.enc','.tgz.enc',$file_name);
    $backup_file = dirname(__FILE__) . '/backups/' . $file_name;
    unlink($backup_file);
    // Redirect back to the dashboard after the restoration process
    wp_safe_redirect(admin_url('admin.php?page=eazibackup-dashboard'));
    exit;
}

// Add the action hook to trigger the restoration process
add_action('admin_post_restore_eazibackup', 'eazibackup_restore');
add_action('admin_post_delete_eazibackup', 'eazibackup_delete');

function validate_premium ($premiumkey) {
error_log("Checking premium key... $premiumkey");
    if ($premiumkey !== "") {
        // API URL
        $api_url = 'https://my-app.gr/eazibackup/validate.php';

        // Data to be sent as POST fields
        $post_data = array(
            'token' => $premiumkey,
            'site' => explode("/",home_url())[2],
        );
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        $response = curl_exec($ch);

        // Check for cURL errors
        if (curl_errno($ch)) {
            error_log('cURL error: ' . curl_error($ch));
            curl_close($ch);
            return false;
        }
        curl_close($ch);

        // Parse the JSON response
        $json_data = json_decode($response, true);
        if ($json_data === null) {
            error_log('JSON parsing error: ' . json_last_error_msg());
            return false;
        }
        if (isset($json_data['key']) && $json_data['key'] === "valid") {
error_log("Premium key validation: success!");
            return true;
        } else {
error_log("Premium key validation: failed!");        
            return false;
        }
    }
}
// function remove_footer_admin () {}
// add_filter('admin_footer_text', 'remove_footer_admin')


?>

