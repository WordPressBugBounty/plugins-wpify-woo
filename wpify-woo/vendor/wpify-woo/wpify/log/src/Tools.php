<?php

namespace WpifyWooDeps\Wpify\Log;

class Tools
{
    private $menu_args;
    public function __construct(array $menu_args = [])
    {
        $this->menu_args = $menu_args;
        add_action('admin_menu', [$this, 'add_menu_page'], 99);
    }
    public function add_menu_page()
    {
        add_submenu_page($this->menu_args['parent_slug'] ?? 'tools.php', $this->menu_args['page_title'] ?? __('WPify Logs', 'wpify-log'), $this->menu_args['menu_title'] ?? __('WPify Logs', 'wpify-log'), $this->menu_args['capability'] ?? 'read', $this->menu_args['menu_slug'] ?? 'wpify-logs', [$this, 'logs_screen'], 109);
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
            <form action="" style="justify-content: start; margin-bottom: 20px">
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

        <div class="wrap">
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
                    echo '<pre>';
                    //						var_dump( $logs );
                    echo '</pre>';
                    ?>

                        <table class="wp-list-table widefat fixed striped table-view-list posts">
                            <thead>
                            <tr>
								<?php 
                    foreach ($header as $item) {
                        ?>
                                    <th style="width: <?php 
                        echo $this->column_width($item);
                        ?>"><?php 
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
                        foreach ($log as $key => $item) {
                            ?>
                                        <td style="width: <?php 
                            echo $this->column_width($key);
                            ?>"><?php 
                            $this->pretty_print_log_item($item);
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
        ?>
        </div>
		<?php 
    }
    public function column_width($key)
    {
        switch ($key) {
            case 'context':
                $width = '44%';
                break;
            case 'level':
            case 'level_name':
            case 'extra':
                $width = '7%';
                break;
            default:
                $width = '15%';
                break;
        }
        return $width;
    }
    public function pretty_print_log_item($item)
    {
        if (is_string($item) && ($item[0] === '{' && str_ends_with($item, '}') || $item[0] === '[' && str_ends_with($item, ']'))) {
            $decoded = json_decode($item, \true);
            if (json_last_error() === \JSON_ERROR_NONE) {
                $item = $decoded;
            }
        }
        if (is_array($item)) {
            $json = json_encode($item, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);
            $body = trim($json);
            if ($body[0] === '{' && substr($body, -1) === '}') {
                $body = substr($body, 1, -1);
            }
            echo '<pre style="white-space: pre-wrap; word-break: break-all; font-size:13px; margin:0;">' . $body . '</pre>';
        } else {
            echo esc_html($item);
        }
    }
}
