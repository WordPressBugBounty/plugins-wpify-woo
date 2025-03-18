<?php

namespace WpifyWooDeps\Wpify\Log;

class Tools
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_menu_page']);
    }
    public function add_menu_page()
    {
        add_submenu_page('tools.php', __('WPify Logs', 'wpify-log'), __('WPify Logs', 'wpify-log'), 'read', 'wpify-logs', [$this, 'logs_screen']);
    }
    public function logs_screen()
    {
        $logs = apply_filters('wpify_logs', []);
        $files = [];
        foreach ($logs as $log) {
            foreach ($log->getHandlers() as $handler) {
                if (!method_exists($handler, 'get_glob_pattern')) {
                    continue;
                }
                $log_files = \glob($handler->get_glob_pattern());
                $files = array_merge($files, $log_files);
            }
        }
        ?>
        <div class="wrap">
            <h2><?php 
        _e('WPify Logs', 'wpify-log');
        ?></h2>
            <form action="">
                <select name="log-file" id="log-file" style="max-width: 300px;">
                    <option value=""></option>
					<?php 
        foreach ($files as $file) {
            ?>
                        <option value="<?php 
            echo $file;
            ?>" <?php 
            echo selected($file, (!empty($_GET['log-file'])) ? esc_html(sanitize_file_name($_GET['log-file'])) : '');
            ?>><?php 
            echo basename($file);
            ?></option>
					<?php 
        }
        ?>
                </select>
                <input type="hidden" name="page" value="wpify-logs"/>
                <input type="submit" value="Display log"/>
            </form>
        </div>

		<?php 
        if (!empty($_GET['log-file'])) {
            $file = str_replace('\\\\', '\\', $_GET['log-file']);
            if (!\in_array($file, $files)) {
                ?>
                <p><?php 
                _e('File not found, cheating, huh?', 'wpify-log');
                ?></p>
				<?php 
            } else {
                $logs = array_map(function ($item) {
                    return json_decode($item, \ARRAY_A);
                }, file($_GET['log-file']));
                if (!empty($logs)) {
                    $header = [];
                    foreach ($logs[0] as $key => $item) {
                        $header[] = $key;
                    }
                    ?>

                    <table class="wp-list-table widefat fixed striped table-view-list posts">
                        <thead>
                        <tr>
							<?php 
                    foreach ($header as $item) {
                        ?>
                                <th><?php 
                        echo $item;
                        ?></th>
							<?php 
                    }
                    ?>
                        </tr>
                        </thead>
                        <tbody>
						<?php 
                    foreach ($logs as $log) {
                        ?>
                            <tr>
								<?php 
                        foreach ($log as $item) {
                            ?>
                                    <td><?php 
                            echo is_array($item) ? json_encode($item) : $item;
                            ?></td>
								<?php 
                        }
                        ?>
                            </tr>
						<?php 
                    }
                    ?>
                        </tbody>
                    </table>
					<?php 
                }
            }
        }
    }
}
