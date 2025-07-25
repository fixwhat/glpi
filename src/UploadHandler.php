<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI - Gestionnaire Libre de Parc Informatique
 *
 * http://glpi-project.org
 *
 * @copyright 2015-2025 Teclib' and contributors.
 * @copyright 2003-2014 by the INDEPNET Development Team.
 * @copyright 2010-2023 Sebastian Tschan.
 * @licence   https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://blueimp.net
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * ---------------------------------------------------------------------
 */

/**
 * @FIXME Merge this class with GLPIUploadHandler in GLPI 11.0.
 *        It has been added in GLPI 10.0.10 to ensure that removal of `blueimp/jquery-file-upload` library would not
 *        result in any BC-break for plugins.
 */

use Safe\Exceptions\FilesystemException;
use Safe\Exceptions\ImageException;

use function Safe\copy;
use function Safe\error_log;
use function Safe\fclose;
use function Safe\file_put_contents;
use function Safe\filemtime;
use function Safe\filesize;
use function Safe\fopen;
use function Safe\fread;
use function Safe\getimagesize;
use function Safe\imagealphablending;
use function Safe\imagecopyresampled;
use function Safe\imagecreatetruecolor;
use function Safe\imagedestroy;
use function Safe\imageflip;
use function Safe\imagerotate;
use function Safe\imagesavealpha;
use function Safe\ini_get;
use function Safe\json_encode;
use function Safe\mkdir;
use function Safe\ob_flush;
use function Safe\parse_url;
use function Safe\preg_match;
use function Safe\preg_replace;
use function Safe\preg_replace_callback;
use function Safe\preg_split;
use function Safe\readfile;
use function Safe\scandir;
use function Safe\session_id;
use function Safe\session_start;
use function Safe\unlink;

class UploadHandler
{
    protected $options;

    // PHP File Upload error message codes:
    // https://php.net/manual/en/features.file-upload.errors.php
    protected $error_messages = [
        1 => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
        2 => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
        3 => 'The uploaded file was only partially uploaded',
        4 => 'No file was uploaded',
        6 => 'Missing a temporary folder',
        7 => 'Failed to write file to disk',
        8 => 'A PHP extension stopped the file upload',
        'post_max_size' => 'The uploaded file exceeds the post_max_size directive in php.ini',
        'max_file_size' => 'File is too big',
        'min_file_size' => 'File is too small',
        'accept_file_types' => 'Filetype not allowed',
        'max_number_of_files' => 'Maximum number of files exceeded',
        'invalid_file_type' => 'Invalid file type',
        'max_width' => 'Image exceeds maximum width',
        'min_width' => 'Image requires a minimum width',
        'max_height' => 'Image exceeds maximum height',
        'min_height' => 'Image requires a minimum height',
        'abort' => 'File upload aborted',
        'image_resize' => 'Failed to resize image',
    ];

    public const IMAGETYPE_GIF = 'image/gif';
    public const IMAGETYPE_JPEG = 'image/jpeg';
    public const IMAGETYPE_PNG = 'image/png';

    protected $image_objects = [];
    protected $response = [];

    public function __construct($options = null, $initialize = false, $error_messages = null)
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $this->options = [
            'script_url' => $this->get_full_url() . '/' . $this->basename($this->get_server_var('SCRIPT_NAME')),
            'upload_dir' => GLPI_TMP_DIR . '/',
            'upload_url' => $this->get_full_url() . '/files/',
            'input_stream' => 'php://input',
            'user_dirs' => false,
            'mkdir_mode' => 0o755,
            'param_name' => 'files',
            // Set the following option to 'POST', if your server does not support
            // DELETE requests. This is a parameter sent to the client:
            'delete_type' => 'DELETE',
            'access_control_allow_origin' => '*',
            'access_control_allow_credentials' => false,
            'access_control_allow_methods' => [
                'OPTIONS',
                'HEAD',
                'GET',
                'POST',
                'PUT',
                'PATCH',
                'DELETE',
            ],
            'access_control_allow_headers' => [
                'Content-Type',
                'Content-Range',
                'Content-Disposition',
            ],
            // By default, allow redirects to the referer protocol+host:
            'redirect_allow_target' => '/^' . preg_quote(
                parse_url($this->get_server_var('HTTP_REFERER'), PHP_URL_SCHEME)
                    . '://'
                    . parse_url($this->get_server_var('HTTP_REFERER'), PHP_URL_HOST)
                    . '/', // Trailing slash to not match subdomains by mistake
                '/' // preg_quote delimiter param
            ) . '/',
            // Enable to provide file downloads via GET requests to the PHP script:
            //     1. Set to 1 to download files via readfile method through PHP
            //     2. Set to 2 to send a X-Sendfile header for lighttpd/Apache
            //     3. Set to 3 to send a X-Accel-Redirect header for nginx
            // If set to 2 or 3, adjust the upload_url option to the base path of
            // the redirect parameter, e.g. '/files/'.
            'download_via_php' => false,
            // Read files in chunks to avoid memory limits when download_via_php
            // is enabled, set to 0 to disable chunked reading of files:
            'readfile_chunk_size' => 10 * 1024 * 1024, // 10 MiB
            // Defines which files can be displayed inline when downloaded:
            'inline_file_types' => '/\.(gif|jpe?g|png)$/i',
            // Defines which files (based on their names) are accepted for upload.
            // By default, only allows file uploads with image file extensions.
            // Only change this setting after making sure that any allowed file
            // types cannot be executed by the webserver in the files directory,
            // e.g. PHP scripts, nor executed by the browser when downloaded,
            // e.g. HTML files with embedded JavaScript code.
            // Please also read the SECURITY.md document in this repository.
            'accept_file_types' => DocumentType::getUploadableFilePattern(),
            // Replaces dots in filenames with the given string.
            // Can be disabled by setting it to false or an empty string.
            // Note that this is a security feature for servers that support
            // multiple file extensions, e.g. the Apache AddHandler Directive:
            // https://httpd.apache.org/docs/current/mod/mod_mime.html#addhandler
            // Before disabling it, make sure that files uploaded with multiple
            // extensions cannot be executed by the webserver, e.g.
            // "example.php.png" with embedded PHP code, nor executed by the
            // browser when downloaded, e.g. "example.html.gif" with embedded
            // JavaScript code.
            'replace_dots_in_filenames' => false,
            // The php.ini settings upload_max_filesize and post_max_size
            // take precedence over the following max_file_size setting:
            'max_file_size' => $CFG_GLPI['document_max_size'] * 1024 * 1024,
            'min_file_size' => 1,
            // The maximum number of files for the upload directory:
            'max_number_of_files' => null,
            // Reads first file bytes to identify and correct file extensions:
            'correct_image_extensions' => false,
            // Image resolution restrictions:
            'max_width' => null,
            'max_height' => null,
            'min_width' => 1,
            'min_height' => 1,
            // Set the following option to false to enable resumable uploads:
            'discard_aborted_uploads' => true,
            'image_versions' => [],
            'print_response' => true,
        ];
        if ($options) {
            $this->options = $options + $this->options;
        }
        if ($error_messages) {
            $this->error_messages = $error_messages + $this->error_messages;
        }
        if ($initialize) {
            $this->initialize();
        }
    }

    protected function initialize()
    {
        switch ($this->get_server_var('REQUEST_METHOD')) {
            case 'OPTIONS':
            case 'HEAD':
                $this->head();
                break;
            case 'GET':
                $this->get($this->options['print_response']);
                break;
            case 'PATCH':
            case 'PUT':
            case 'POST':
                $this->post($this->options['print_response']);
                break;
            case 'DELETE':
                $this->delete($this->options['print_response']);
                break;
            default:
                $this->header('HTTP/1.1 405 Method Not Allowed');
        }
    }

    protected function get_full_url()
    {
        $https = !empty($_SERVER['HTTPS']) && strcasecmp($_SERVER['HTTPS'], 'on') === 0 ||
            !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
            strcasecmp($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') === 0;
        return
            ($https ? 'https://' : 'http://') .
            (!empty($_SERVER['REMOTE_USER']) ? $_SERVER['REMOTE_USER'] . '@' : '') .
            ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] .
                ($https && $_SERVER['SERVER_PORT'] === 443 ||
                $_SERVER['SERVER_PORT'] === 80 ? '' : ':' . $_SERVER['SERVER_PORT']))) .
            substr($_SERVER['SCRIPT_NAME'], 0, strrpos($_SERVER['SCRIPT_NAME'], '/'));
    }

    protected function get_user_id()
    {
        @session_start();
        return session_id();
    }

    protected function get_user_path()
    {
        if ($this->options['user_dirs']) {
            return $this->get_user_id() . '/';
        }
        return '';
    }

    protected function get_upload_path($file_name = null, $version = null)
    {
        $file_name = $file_name ?: '';
        if (empty($version)) {
            $version_path = '';
        } else {
            $version_dir = @$this->options['image_versions'][$version]['upload_dir'];
            if ($version_dir) {
                return $version_dir . $this->get_user_path() . $file_name;
            }
            $version_path = $version . '/';
        }
        return $this->options['upload_dir'] . $this->get_user_path()
            . $version_path . $file_name;
    }

    protected function get_query_separator($url)
    {
        return !str_contains($url, '?') ? '?' : '&';
    }

    protected function get_download_url($file_name, $version = null, $direct = false)
    {
        if (!$direct && $this->options['download_via_php']) {
            $url = $this->options['script_url']
                . $this->get_query_separator($this->options['script_url'])
                . $this->get_singular_param_name()
                . '=' . rawurlencode($file_name);
            if ($version) {
                $url .= '&version=' . rawurlencode($version);
            }
            return $url . '&download=1';
        }
        if (empty($version)) {
            $version_path = '';
        } else {
            $version_url = @$this->options['image_versions'][$version]['upload_url'];
            if ($version_url) {
                return $version_url . $this->get_user_path() . rawurlencode($file_name);
            }
            $version_path = rawurlencode($version) . '/';
        }
        return $this->options['upload_url'] . $this->get_user_path()
            . $version_path . rawurlencode($file_name);
    }

    protected function set_additional_file_properties($file)
    {
        $file->deleteUrl = $this->options['script_url']
            . $this->get_query_separator($this->options['script_url'])
            . $this->get_singular_param_name()
            . '=' . rawurlencode($file->name);
        $file->deleteType = $this->options['delete_type'];
        if ($file->deleteType !== 'DELETE') {
            $file->deleteUrl .= '&_method=DELETE';
        }
        if ($this->options['access_control_allow_credentials']) {
            $file->deleteWithCredentials = true;
        }
    }

    // Fix for overflowing signed 32 bit integers,
    // works for sizes up to 2^32-1 bytes (4 GiB - 1):
    protected function fix_integer_overflow($size)
    {
        if ($size < 0) {
            $size += 2.0 * (PHP_INT_MAX + 1);
        }
        return $size;
    }

    protected function get_file_size($file_path, $clear_stat_cache = false)
    {
        if ($clear_stat_cache) {
            if (version_compare(PHP_VERSION, '5.3.0') >= 0) {
                clearstatcache(true, $file_path);
            } else {
                clearstatcache();
            }
        }
        return $this->fix_integer_overflow(filesize($file_path));
    }

    protected function is_valid_file_object($file_name)
    {
        $file_path = $this->get_upload_path($file_name);
        if (strlen($file_name) > 0 && $file_name[0] !== '.' && is_file($file_path)) {
            return true;
        }
        return false;
    }

    protected function get_file_object($file_name)
    {
        if ($this->is_valid_file_object($file_name)) {
            $file = new stdClass();
            $file->name = $file_name;
            $file->size = $this->get_file_size(
                $this->get_upload_path($file_name)
            );
            $file->url = $this->get_download_url($file->name);
            foreach ($this->options['image_versions'] as $version => $options) {
                if (!empty($version)) {
                    if (is_file($this->get_upload_path($file_name, $version))) {
                        $file->{$version . 'Url'} = $this->get_download_url(
                            $file->name,
                            $version
                        );
                    }
                }
            }
            $this->set_additional_file_properties($file);
            return $file;
        }
        return null;
    }

    protected function get_file_objects($iteration_method = 'get_file_object')
    {
        $upload_dir = $this->get_upload_path();
        if (!is_dir($upload_dir)) {
            return [];
        }
        return array_values(array_filter(array_map(
            [$this, $iteration_method],
            scandir($upload_dir)
        )));
    }

    protected function count_file_objects()
    {
        return count($this->get_file_objects('is_valid_file_object'));
    }

    protected function get_error_message($error)
    {
        switch ($error) {
            case UPLOAD_ERR_INI_SIZE:
                return __('The uploaded file exceeds the upload_max_filesize directive in php.ini');

            case UPLOAD_ERR_FORM_SIZE:
                return __('The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form');

            case UPLOAD_ERR_PARTIAL:
                return __('The uploaded file was only partially uploaded');

            case UPLOAD_ERR_NO_FILE:
                return __('No file was uploaded');

            case UPLOAD_ERR_NO_TMP_DIR:
                return __('Missing a temporary folder');

            case UPLOAD_ERR_CANT_WRITE:
                return __('Failed to write file to disk');

            case UPLOAD_ERR_EXTENSION:
                return __('A PHP extension stopped the file upload');

            case 'post_max_size':
                return __('The uploaded file exceeds the post_max_size directive in php.ini');

            case 'max_file_size':
                return __('File is too big');

            case 'min_file_size':
                return __('File is too small');

            case 'max_number_of_files':
                return __('Maximum number of files exceeded');

            case 'max_width':
                return __('Image exceeds maximum width');

            case 'min_width':
                return __('Image requires a minimum width');

            case 'max_height':
                return __('Image exceeds maximum height');

            case 'min_height':
                return __('Image requires a minimum height');

            case 'accept_file_types':
                return __('Filetype not allowed');

            case 'abort':
                return __('File upload aborted');

            case 'image_resize':
                return __('Failed to resize image');
        }

        return false;
    }

    public function get_config_bytes($val)
    {
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        if (is_numeric($val)) {
            $val = (int) $val;
        } else {
            $val = (int) substr($val, 0, -1);
        }
        switch ($last) {
            case 'g':
                $val *= 1024;
                // no-break: apply following multipliers
                // no break
            case 'm':
                $val *= 1024;
                // no-break: apply following multipliers
                // no break
            case 'k':
                $val *= 1024;
                // no-break: apply following multipliers
        }
        return $this->fix_integer_overflow($val);
    }

    protected function validate_image_file($uploaded_file, $file, $error, $index)
    {
        if ($this->imagetype($uploaded_file) !== $this->get_file_type($file->name)) {
            $file->error = $this->get_error_message('invalid_file_type');
            return false;
        }
        $max_width = @$this->options['max_width'];
        $max_height = @$this->options['max_height'];
        $min_width = @$this->options['min_width'];
        $min_height = @$this->options['min_height'];
        if ($max_width || $max_height || $min_width || $min_height) {
            [$img_width, $img_height] = $this->get_image_size($uploaded_file);
            if (!empty($img_width) && !empty($img_height)) {
                if ($max_width && $img_width > $max_width) {
                    $file->error = $this->get_error_message('max_width');
                    return false;
                }
                if ($max_height && $img_height > $max_height) {
                    $file->error = $this->get_error_message('max_height');
                    return false;
                }
                if ($min_width && $img_width < $min_width) {
                    $file->error = $this->get_error_message('min_width');
                    return false;
                }
                if ($min_height && $img_height < $min_height) {
                    $file->error = $this->get_error_message('min_height');
                    return false;
                }
            }
        }
        return true;
    }

    protected function validate($uploaded_file, $file, $error, $index, $content_range)
    {
        if ($error) {
            $file->error = $this->get_error_message($error);
            return false;
        }
        $content_length = $this->fix_integer_overflow(
            (int) $this->get_server_var('CONTENT_LENGTH')
        );
        $post_max_size = $this->get_config_bytes(ini_get('post_max_size'));
        if ($post_max_size && ($content_length > $post_max_size)) {
            $file->error = $this->get_error_message('post_max_size');
            return false;
        }
        if (!preg_match($this->options['accept_file_types'], $file->name)) {
            $file->error = $this->get_error_message('accept_file_types');
            return false;
        }
        if ($uploaded_file && is_uploaded_file($uploaded_file)) {
            $file_size = $this->get_file_size($uploaded_file);
        } else {
            $file_size = $content_length;
        }
        if (
            $this->options['max_file_size'] && (
                $file_size > $this->options['max_file_size'] ||
                $file->size > $this->options['max_file_size']
            )
        ) {
            $file->error = $this->get_error_message('max_file_size');
            return false;
        }
        if (
            $this->options['min_file_size'] &&
            $file_size < $this->options['min_file_size']
        ) {
            $file->error = $this->get_error_message('min_file_size');
            return false;
        }
        if (
            is_int($this->options['max_number_of_files']) &&
            ($this->count_file_objects() >= $this->options['max_number_of_files']) &&
            // Ignore additional chunks of existing files:
            !is_file($this->get_upload_path($file->name))
        ) {
            $file->error = $this->get_error_message('max_number_of_files');
            return false;
        }
        if (!$content_range && $this->has_image_file_extension($file->name)) {
            return $this->validate_image_file($uploaded_file, $file, $error, $index);
        }
        return true;
    }

    protected function upcount_name_callback($matches)
    {
        $index = isset($matches[1]) ? ((int) $matches[1]) + 1 : 1;
        $ext = $matches[2] ?? '';
        return ' (' . $index . ')' . $ext;
    }

    protected function upcount_name($name)
    {
        return preg_replace_callback(
            '/(?:(?: \(([\d]+)\))?(\.[^.]+))?$/',
            [$this, 'upcount_name_callback'],
            $name,
            1
        );
    }

    protected function get_unique_filename(
        $file_path,
        $name,
        $size,
        $type,
        $error,
        $index,
        $content_range
    ) {
        while (is_dir($this->get_upload_path($name))) {
            $name = $this->upcount_name($name);
        }
        // Keep an existing filename if this is part of a chunked upload:
        $uploaded_bytes = $this->fix_integer_overflow((int) @$content_range[1]);
        while (is_file($this->get_upload_path($name))) {
            if (
                $uploaded_bytes === $this->get_file_size(
                    $this->get_upload_path($name)
                )
            ) {
                break;
            }
            $name = $this->upcount_name($name);
        }
        return $name;
    }

    protected function get_valid_image_extensions($file_path)
    {
        switch ($this->imagetype($file_path)) {
            case self::IMAGETYPE_JPEG:
                return ['jpg', 'jpeg'];
            case self::IMAGETYPE_PNG:
                return  ['png'];
            case self::IMAGETYPE_GIF:
                return ['gif'];
        }
    }

    protected function fix_file_extension(
        $file_path,
        $name,
        $size,
        $type,
        $error,
        $index,
        $content_range
    ) {
        // Add missing file extension for known image types:
        if (
            !str_contains($name, '.') &&
            preg_match('/^image\/(gif|jpe?g|png)/', $type, $matches)
        ) {
            $name .= '.' . $matches[1];
        }
        if ($this->options['correct_image_extensions']) {
            $extensions = $this->get_valid_image_extensions($file_path);
            // Adjust incorrect image file extensions:
            if (!empty($extensions)) {
                $parts = explode('.', $name);
                $extIndex = count($parts) - 1;
                $ext = strtolower(@$parts[$extIndex]);
                if (!in_array($ext, $extensions)) {
                    $parts[$extIndex] = $extensions[0];
                    $name = implode('.', $parts);
                }
            }
        }
        return $name;
    }

    protected function trim_file_name(
        $file_path,
        $name,
        $size,
        $type,
        $error,
        $index,
        $content_range
    ) {
        // Remove path information and dots around the filename, to prevent uploading
        // into different directories or replacing hidden system files.
        // Also remove control characters and spaces (\x00..\x20) around the filename:
        $name = trim($this->basename(stripslashes($name)), ".\x00..\x20");
        // Replace dots in filenames to avoid security issues with servers
        // that interpret multiple file extensions, e.g. "example.php.png":
        $replacement = $this->options['replace_dots_in_filenames'];
        if (!empty($replacement)) {
            $parts = explode('.', $name);
            if (count($parts) > 2) {
                $ext = array_pop($parts);
                $name = implode($replacement, $parts) . '.' . $ext;
            }
        }
        // Use a timestamp for empty filenames:
        if (!$name) {
            $name = str_replace('.', '-', (string) microtime(true));
        }
        return $name;
    }

    protected function get_file_name(
        $file_path,
        $name,
        $size,
        $type,
        $error,
        $index,
        $content_range
    ) {
        $name = $this->trim_file_name(
            $file_path,
            $name,
            $size,
            $type,
            $error,
            $index,
            $content_range
        );
        return $this->get_unique_filename(
            $file_path,
            $this->fix_file_extension(
                $file_path,
                $name,
                $size,
                $type,
                $error,
                $index,
                $content_range
            ),
            $size,
            $type,
            $error,
            $index,
            $content_range
        );
    }

    protected function get_scaled_image_file_paths($file_name, $version)
    {
        $file_path = $this->get_upload_path($file_name);
        if (!empty($version)) {
            $version_dir = $this->get_upload_path(null, $version);
            if (!is_dir($version_dir)) {
                mkdir($version_dir, $this->options['mkdir_mode'], true);
            }
            $new_file_path = $version_dir . '/' . $file_name;
        } else {
            $new_file_path = $file_path;
        }
        return [$file_path, $new_file_path];
    }

    protected function gd_get_image_object($file_path, $func, $no_cache = false)
    {
        if (empty($this->image_objects[$file_path]) || $no_cache) {
            $this->gd_destroy_image_object($file_path);
            $this->image_objects[$file_path] = $func($file_path);
        }
        return $this->image_objects[$file_path];
    }

    protected function gd_set_image_object($file_path, $image)
    {
        $this->gd_destroy_image_object($file_path);
        $this->image_objects[$file_path] = $image;
    }

    protected function gd_destroy_image_object($file_path)
    {
        $image = $this->image_objects[$file_path] ?? null ;
        if ($image) {
            try {
                imagedestroy($image);
                return true;
            } catch (ImageException $e) {
                return false;
            }
        }
        return false;
    }

    protected function gd_imageflip($image, $mode)
    {
        if (function_exists('imageflip')) {
            try {
                imageflip($image, $mode);
                return true;
            } catch (ImageException $e) {
                return false;
            }
        }
        $new_width = $src_width = imagesx($image);
        $new_height = $src_height = imagesy($image);
        $new_img = imagecreatetruecolor($new_width, $new_height);
        $src_x = 0;
        $src_y = 0;
        switch ($mode) {
            case '1': // flip on the horizontal axis
                $src_y = $new_height - 1;
                $src_height = -$new_height;
                break;
            case '2': // flip on the vertical axis
                $src_x  = $new_width - 1;
                $src_width = -$new_width;
                break;
            case '3': // flip on both axes
                $src_y = $new_height - 1;
                $src_height = -$new_height;
                $src_x  = $new_width - 1;
                $src_width = -$new_width;
                break;
            default:
                return $image;
        }
        imagecopyresampled(
            $new_img,
            $image,
            0,
            0,
            $src_x,
            $src_y,
            $new_width,
            $new_height,
            $src_width,
            $src_height
        );
        return $new_img;
    }

    protected function gd_orient_image($file_path, $src_img)
    {
        if (!function_exists('exif_read_data')) {
            return false;
        }
        $exif = @exif_read_data($file_path);
        if ($exif === false) {
            return false;
        }
        $orientation = (int) @$exif['Orientation'];
        if ($orientation < 2 || $orientation > 8) {
            return false;
        }
        switch ($orientation) {
            case 2:
                $new_img = $this->gd_imageflip(
                    $src_img,
                    defined('IMG_FLIP_VERTICAL') ? IMG_FLIP_VERTICAL : 2
                );
                break;
            case 3:
                $new_img = imagerotate($src_img, 180, 0);
                break;
            case 4:
                $new_img = $this->gd_imageflip(
                    $src_img,
                    defined('IMG_FLIP_HORIZONTAL') ? IMG_FLIP_HORIZONTAL : 1
                );
                break;
            case 5:
                $tmp_img = $this->gd_imageflip(
                    $src_img,
                    defined('IMG_FLIP_HORIZONTAL') ? IMG_FLIP_HORIZONTAL : 1
                );
                $new_img = imagerotate($tmp_img, 270, 0);
                imagedestroy($tmp_img);
                break;
            case 6:
                $new_img = imagerotate($src_img, 270, 0);
                break;
            case 7:
                $tmp_img = $this->gd_imageflip(
                    $src_img,
                    defined('IMG_FLIP_VERTICAL') ? IMG_FLIP_VERTICAL : 2
                );
                $new_img = imagerotate($tmp_img, 270, 0);
                imagedestroy($tmp_img);
                break;
            case 8:
                $new_img = imagerotate($src_img, 90, 0);
                break;
            default:
                return false;
        }
        $this->gd_set_image_object($file_path, $new_img);
        return true;
    }

    protected function gd_create_scaled_image($file_name, $version, $options)
    {
        if (!function_exists('imagecreatetruecolor')) {
            error_log('Function not found: imagecreatetruecolor');
            return false;
        }
        [$file_path, $new_file_path] =
            $this->get_scaled_image_file_paths($file_name, $version);
        $type = strtolower(substr(strrchr($file_name, '.'), 1));
        switch ($type) {
            case 'jpg':
            case 'jpeg':
                $src_func = 'imagecreatefromjpeg';
                $write_func = 'imagejpeg';
                $image_quality = $options['jpeg_quality'] ?? 75;
                break;
            case 'gif':
                $src_func = 'imagecreatefromgif';
                $write_func = 'imagegif';
                $image_quality = null;
                break;
            case 'png':
                $src_func = 'imagecreatefrompng';
                $write_func = 'imagepng';
                $image_quality = $options['png_quality'] ?? 9;
                break;
            default:
                return false;
        }
        $src_img = $this->gd_get_image_object(
            $file_path,
            $src_func,
            !empty($options['no_cache'])
        );
        $image_oriented = false;
        if (
            !empty($options['auto_orient']) && $this->gd_orient_image(
                $file_path,
                $src_img
            )
        ) {
            $image_oriented = true;
            $src_img = $this->gd_get_image_object(
                $file_path,
                $src_func
            );
        }
        $max_width = $img_width = imagesx($src_img);
        $max_height = $img_height = imagesy($src_img);
        if (!empty($options['max_width'])) {
            $max_width = $options['max_width'];
        }
        if (!empty($options['max_height'])) {
            $max_height = $options['max_height'];
        }
        $scale = min(
            $max_width / $img_width,
            $max_height / $img_height
        );
        if ($scale >= 1) {
            if ($image_oriented) {
                return $write_func($src_img, $new_file_path, $image_quality);
            }
            if ($file_path !== $new_file_path) {
                try {
                    copy($file_path, $new_file_path);
                    return true;
                } catch (FilesystemException $e) {
                    return false;
                }
            }
            return true;
        }
        if (empty($options['crop'])) {
            $new_width = $img_width * $scale;
            $new_height = $img_height * $scale;
            $dst_x = 0;
            $dst_y = 0;
            $new_img = imagecreatetruecolor($new_width, $new_height);
        } else {
            if (($img_width / $img_height) >= ($max_width / $max_height)) {
                $new_width = $img_width / ($img_height / $max_height);
                $new_height = $max_height;
            } else {
                $new_width = $max_width;
                $new_height = $img_height / ($img_width / $max_width);
            }
            $dst_x = 0 - ($new_width - $max_width) / 2;
            $dst_y = 0 - ($new_height - $max_height) / 2;
            $new_img = imagecreatetruecolor($max_width, $max_height);
        }
        // Handle transparency in GIF and PNG images:
        switch ($type) {
            case 'gif':
                imagecolortransparent($new_img, imagecolorallocate($new_img, 0, 0, 0));
                break;
            case 'png':
                imagecolortransparent($new_img, imagecolorallocate($new_img, 0, 0, 0));
                imagealphablending($new_img, false);
                imagesavealpha($new_img, true);
                break;
        }
        try {
            imagecopyresampled(
                $new_img,
                $src_img,
                $dst_x,
                $dst_y,
                0,
                0,
                $new_width,
                $new_height,
                $img_width,
                $img_height
            );
            $write_func($new_img, $new_file_path, $image_quality);
            $this->gd_set_image_object($file_path, $new_img);
            return true;
        } catch (ImageException $e) {
            return false;
        }
    }

    protected function get_image_size($file_path)
    {
        if (!function_exists('getimagesize')) {
            error_log('Function not found: getimagesize');
            return false;
        }
        return @getimagesize($file_path);
    }

    protected function create_scaled_image($file_name, $version, $options)
    {
        try {
            return $this->gd_create_scaled_image($file_name, $version, $options);
        } catch (Exception $e) {
            error_log($e->getMessage());
            return false;
        }
    }

    protected function destroy_image_object($file_path)
    {
        // nothing to do
    }

    protected function imagetype($file_path)
    {
        $fp = fopen($file_path, 'r');
        $data = fread($fp, 4);
        fclose($fp);
        // GIF: 47 49 46 38
        if ($data === 'GIF8') {
            return self::IMAGETYPE_GIF;
        }
        // JPG: FF D8 FF
        if (bin2hex(substr($data, 0, 3)) === 'ffd8ff') {
            return self::IMAGETYPE_JPEG;
        }
        // PNG: 89 50 4E 47
        if (bin2hex(@$data[0]) . substr($data, 1, 4) === '89PNG') {
            return self::IMAGETYPE_PNG;
        }
        return false;
    }

    protected function is_valid_image_file($file_path)
    {
        return (bool) $this->imagetype($file_path);
    }

    protected function has_image_file_extension($file_path)
    {
        return (bool) preg_match('/\.(gif|jpe?g|png)$/i', $file_path);
    }

    protected function handle_image_file($file_path, $file)
    {
        $failed_versions = [];
        foreach ($this->options['image_versions'] as $version => $options) {
            if ($this->create_scaled_image($file->name, $version, $options)) {
                if (!empty($version)) {
                    $file->{$version . 'Url'} = $this->get_download_url(
                        $file->name,
                        $version
                    );
                } else {
                    $file->size = $this->get_file_size($file_path, true);
                }
            } else {
                $failed_versions[] = $version ?: 'original';
            }
        }
        if (count($failed_versions)) {
            $file->error = $this->get_error_message('image_resize')
                . ' (' . implode(', ', $failed_versions) . ')';
        }
        // Free memory:
        $this->destroy_image_object($file_path);
    }

    protected function handle_file_upload(
        $uploaded_file,
        $name,
        $size,
        $type,
        $error,
        $index = null,
        $content_range = null
    ) {
        $file = new stdClass();
        $file->name = $this->get_file_name(
            $uploaded_file,
            $name,
            $size,
            $type,
            $error,
            $index,
            $content_range
        );
        $file->size = $this->fix_integer_overflow((int) $size);
        $file->type = $type;
        if ($this->validate($uploaded_file, $file, $error, $index, $content_range)) {
            $this->handle_form_data($file, $index);
            $upload_dir = $this->get_upload_path();
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, $this->options['mkdir_mode'], true);
            }
            $file_path = $this->get_upload_path($file->name);
            $append_file = $content_range && is_file($file_path) &&
                $file->size > $this->get_file_size($file_path);
            if ($uploaded_file && is_uploaded_file($uploaded_file)) {
                // multipart/formdata uploads (POST method uploads)
                if ($append_file) {
                    file_put_contents(
                        $file_path,
                        fopen($uploaded_file, 'r'),
                        FILE_APPEND
                    );
                } else {
                    move_uploaded_file($uploaded_file, $file_path);
                }
            } else {
                // Non-multipart uploads (PUT method support)
                file_put_contents(
                    $file_path,
                    fopen($this->options['input_stream'], 'r'),
                    $append_file ? FILE_APPEND : 0
                );
            }
            $file_size = $this->get_file_size($file_path, $append_file);
            if ($file_size === $file->size) {
                $file->url = $this->get_download_url($file->name);
                if ($this->has_image_file_extension($file->name)) {
                    if ($content_range && !$this->validate_image_file($file_path, $file, $error, $index)) {
                        unlink($file_path);
                    } else {
                        $this->handle_image_file($file_path, $file);
                    }
                }
            } else {
                $file->size = $file_size;
                if (!$content_range && $this->options['discard_aborted_uploads']) {
                    unlink($file_path);
                    $file->error = $this->get_error_message('abort');
                }
            }
            $this->set_additional_file_properties($file);
        }
        return $file;
    }

    protected function readfile($file_path)
    {
        $file_size = $this->get_file_size($file_path);
        $chunk_size = $this->options['readfile_chunk_size'];
        if ($chunk_size && $file_size > $chunk_size) {
            $handle = fopen($file_path, 'rb');
            while (!feof($handle)) {
                echo fread($handle, $chunk_size);
                @ob_flush();
                @flush();
            }
            fclose($handle);
            return $file_size;
        }
        return readfile($file_path);
    }

    protected function body($str)
    {
        echo $str;
    }

    protected function header($str)
    {
        header($str);
    }

    protected function get_upload_data($id)
    {
        return array_key_exists($id, $_FILES) ? $_FILES[$id] : '';
    }

    protected function get_post_param($id)
    {
        return array_key_exists($id, $_POST) ? $_POST[$id] : '';
    }

    protected function get_query_param($id)
    {
        return array_key_exists($id, $_GET) ? $_GET[$id] : '';
    }

    protected function get_server_var($id)
    {
        return array_key_exists($id, $_SERVER) ? $_SERVER[$id] : '';
    }

    protected function handle_form_data($file, $index)
    {
        // Handle form data, e.g. $_POST['description'][$index]
    }

    protected function get_version_param()
    {
        return $this->basename(stripslashes($this->get_query_param('version')));
    }

    protected function get_singular_param_name()
    {
        return substr($this->options['param_name'], 0, -1);
    }

    protected function get_file_name_param()
    {
        $name = $this->get_singular_param_name();
        return $this->basename(stripslashes($this->get_query_param($name)));
    }

    protected function get_file_names_params()
    {
        $params = $this->get_query_param($this->options['param_name']);
        if (!$params) {
            return null;
        }
        foreach ($params as $key => $value) {
            $params[$key] = $this->basename(stripslashes($value));
        }
        return $params;
    }

    protected function get_file_type($file_path)
    {
        switch (strtolower(pathinfo($file_path, PATHINFO_EXTENSION))) {
            case 'jpeg':
            case 'jpg':
                return self::IMAGETYPE_JPEG;
            case 'png':
                return self::IMAGETYPE_PNG;
            case 'gif':
                return self::IMAGETYPE_GIF;
            default:
                return '';
        }
    }

    protected function download()
    {
        switch ($this->options['download_via_php']) {
            case 1:
                $redirect_header = null;
                break;
            case 2:
                $redirect_header = 'X-Sendfile';
                break;
            case 3:
                $redirect_header = 'X-Accel-Redirect';
                break;
            default:
                return $this->header('HTTP/1.1 403 Forbidden');
        }
        $file_name = $this->get_file_name_param();
        if (!$this->is_valid_file_object($file_name)) {
            return $this->header('HTTP/1.1 404 Not Found');
        }
        if ($redirect_header) {
            return $this->header(
                $redirect_header . ': ' . $this->get_download_url(
                    $file_name,
                    $this->get_version_param(),
                    true
                )
            );
        }
        $file_path = $this->get_upload_path($file_name, $this->get_version_param());
        // Prevent browsers from MIME-sniffing the content-type:
        $this->header('X-Content-Type-Options: nosniff');
        if (!preg_match($this->options['inline_file_types'], $file_name)) {
            $this->header('Content-Type: application/octet-stream');
            $this->header('Content-Disposition: attachment; filename="' . $file_name . '"');
        } else {
            $this->header('Content-Type: ' . $this->get_file_type($file_path));
            $this->header('Content-Disposition: inline; filename="' . $file_name . '"');
        }
        $this->header('Content-Length: ' . $this->get_file_size($file_path));
        $this->header('Last-Modified: ' . gmdate('D, d M Y H:i:s T', filemtime($file_path)));
        $this->readfile($file_path);
    }

    protected function send_content_type_header()
    {
        $this->header('Vary: Accept');
        if (str_contains($this->get_server_var('HTTP_ACCEPT'), 'application/json')) {
            $this->header('Content-type: application/json');
        } else {
            $this->header('Content-type: text/plain');
        }
    }

    protected function send_access_control_headers()
    {
        $this->header('Access-Control-Allow-Origin: ' . $this->options['access_control_allow_origin']);
        $this->header('Access-Control-Allow-Credentials: '
            . ($this->options['access_control_allow_credentials'] ? 'true' : 'false'));
        $this->header('Access-Control-Allow-Methods: '
            . implode(', ', $this->options['access_control_allow_methods']));
        $this->header('Access-Control-Allow-Headers: '
            . implode(', ', $this->options['access_control_allow_headers']));
    }

    public function generate_response($content, $print_response = true)
    {
        $this->response = $content;
        if ($print_response) {
            $json = json_encode($content);
            $redirect = stripslashes($this->get_post_param('redirect'));
            if ($redirect && preg_match($this->options['redirect_allow_target'], $redirect)) {
                return $this->header('Location: ' . sprintf($redirect, rawurlencode($json)));
            }
            $this->head();
            if ($this->get_server_var('HTTP_CONTENT_RANGE')) {
                $files = $content[$this->options['param_name']] ?? null;
                if ($files && is_array($files) && is_object($files[0]) && $files[0]->size) {
                    $this->header('Range: 0-' . (
                        $this->fix_integer_overflow((int) $files[0]->size) - 1
                    ));
                }
            }
            $this->body($json);
        }
        return $content;
    }

    public function get_response()
    {
        return $this->response;
    }

    public function head()
    {
        $this->header('Pragma: no-cache');
        $this->header('Cache-Control: no-store, no-cache, must-revalidate');
        $this->header('Content-Disposition: inline; filename="files.json"');
        // Prevent Internet Explorer from MIME-sniffing the content-type:
        $this->header('X-Content-Type-Options: nosniff');
        if ($this->options['access_control_allow_origin']) {
            $this->send_access_control_headers();
        }
        $this->send_content_type_header();
    }

    public function get($print_response = true)
    {
        if ($print_response && $this->get_query_param('download')) {
            return $this->download();
        }
        $file_name = $this->get_file_name_param();
        if ($file_name) {
            $response = [
                $this->get_singular_param_name() => $this->get_file_object($file_name),
            ];
        } else {
            $response = [
                $this->options['param_name'] => $this->get_file_objects(),
            ];
        }
        return $this->generate_response($response, $print_response);
    }

    public function post($print_response = true)
    {
        if ($this->get_query_param('_method') === 'DELETE') {
            return $this->delete($print_response);
        }
        $upload = $this->get_upload_data($this->options['param_name']);
        // Parse the Content-Disposition header, if available:
        $content_disposition_header = $this->get_server_var('HTTP_CONTENT_DISPOSITION');
        $file_name = $content_disposition_header ?
            rawurldecode(preg_replace(
                '/(^[^"]+")|("$)/',
                '',
                $content_disposition_header
            )) : null;
        // Parse the Content-Range header, which has the following form:
        // Content-Range: bytes 0-524287/2000000
        $content_range_header = $this->get_server_var('HTTP_CONTENT_RANGE');
        $content_range = $content_range_header ?
            preg_split('/[^0-9]+/', $content_range_header) : null;
        $size =  @$content_range[3];
        $files = [];
        if ($upload) {
            if (is_array($upload['tmp_name'])) {
                // param_name is an array identifier like "files[]",
                // $upload is a multi-dimensional array:
                foreach (array_keys($upload['tmp_name']) as $index) {
                    $files[] = $this->handle_file_upload(
                        $upload['tmp_name'][$index],
                        $file_name ?: $upload['name'][$index],
                        $size ?: $upload['size'][$index],
                        $upload['type'][$index],
                        $upload['error'][$index],
                        $index,
                        $content_range
                    );
                }
            } else {
                // param_name is a single object identifier like "file",
                // $upload is a one-dimensional array:
                $files[] = $this->handle_file_upload(
                    $upload['tmp_name'] ?? null,
                    $file_name ?: $upload['name'] ?? null,
                    $size ?: $upload['size'] ?? $this->get_server_var('CONTENT_LENGTH'),
                    $upload['type'] ?? $this->get_server_var('CONTENT_TYPE'),
                    $upload['error'] ?? null,
                    null,
                    $content_range
                );
            }
        }
        $response = [$this->options['param_name'] => $files];
        return $this->generate_response($response, $print_response);
    }

    public function delete($print_response = true)
    {
        $file_names = $this->get_file_names_params();
        if (empty($file_names)) {
            $file_names = [$this->get_file_name_param()];
        }
        $response = [];
        foreach ($file_names as $file_name) {
            $file_path = $this->get_upload_path($file_name);
            $success = strlen($file_name) > 0 && $file_name[0] !== '.' && is_file($file_path) && \unlink($file_path); //@phpstan-ignore theCodingMachineSafe.function
            if ($success) {
                foreach ($this->options['image_versions'] as $version => $options) {
                    if (!empty($version)) {
                        $file = $this->get_upload_path($file_name, $version);
                        if (is_file($file)) {
                            unlink($file);
                        }
                    }
                }
            }
            $response[$file_name] = $success;
        }
        return $this->generate_response($response, $print_response);
    }

    protected function basename($filepath, $suffix = '')
    {
        $splited = preg_split('/\//', rtrim($filepath, '/ '));
        return substr(basename('X' . $splited[count($splited) - 1], $suffix), 1);
    }
}
