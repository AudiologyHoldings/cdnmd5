<?php
/**
 * AWS S3 Adaptor for Cdnmd5
 *
 * see Cdnmd5 for all documentation
 *
 * Uses the same AWS SDK and credential patterns as Lib/AWSS3.php.
 * AWSS3 cannot be instantiated directly here because its constructor is
 * coupled to athena/atlas accounts; this adaptor initialises S3Client
 * directly from the CdnMd5 config instead.
 *
 * Requires aws/aws-sdk-php (loaded via Composer autoloader).
 */

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class Cdnmd5S3 {

    /**
     * Config passed in from Cdnmd5
     */
    public $config = array(
        'CDN' => array(
            'type'   => 'S3',
            'bucket' => '',
            'region' => 'us-east-1',
            'http'   => '',
            'https'  => '',
            'acl'    => 'public-read',
            'auth'   => array(
                'access_key' => '',
                'secret_key' => '',
            ),
        ),
    );

    /**
     * Log of actions taken
     */
    public $log = array();

    /**
     * Cached S3Client instance; reset when config changes
     */
    private $s3Client = null;

    /**
     * Returns a singleton instance of the class.
     *
     * @param array $config
     * @return Cdnmd5S3
     */
    public static function &getInstance($config = array()) {
        static $instance = array();
        if (!$instance) {
            $instance[0] = new Cdnmd5S3();
        }
        if (empty($config)) {
            // nothing
        } elseif (is_array($config)) {
            $instance[0]->config = Set::merge($instance[0]->config, $config);
            $instance[0]->s3Client = null; // reset when config changes
        }
        return $instance[0];
    }

    /**
     * Setup and do the file transfer, or skip if the file already exists
     * (unless forced).
     *
     * @param string  $sourcefile      full path to local file
     * @param string  $target_filename key/basename for the S3 object
     * @param array   $config
     * @param boolean $force
     * @return boolean
     */
    public static function transfer($sourcefile, $target_filename, $config, $force = false) {
        // Prepend the directory to the target filename
        if (isset($config['CDN']['directory']) && !empty($config['CDN']['directory'])) {
            $target_filename = $config['CDN']['directory'] . '/' . ltrim($target_filename, '/');
        }
        $_this = Cdnmd5S3::getInstance($config);
        if (!$force && $_this->fileExists($target_filename)) {
            return true;
        }
        return $_this->sendFile($sourcefile, $target_filename);
    }

    /**
     * Initialise and return the S3Client, reusing the cached instance.
     * Follows the same credential setup pattern as Lib/AWSS3.php.
     *
     * @return S3Client
     */
    private function client() {
        if ($this->s3Client !== null) {
            return $this->s3Client;
        }
        $auth = $this->config['CDN']['auth'];
        $this->s3Client = new S3Client([
            'version'     => 'latest',
            'region'      => $this->config['CDN']['region'],
            'credentials' => [
                'key'    => $auth['access_key'],
                'secret' => $auth['secret_key'],
            ],
            'suppress_php_deprecation_warning' => true,
        ]);
        return $this->s3Client;
    }

    /**
     * Check whether an object already exists in the bucket.
     *
     * @param string $key
     * @return boolean
     */
    private function fileExists($key) {
        return $this->client()->doesObjectExist($this->config['CDN']['bucket'], $key);
    }

    /**
     * Upload a local file to S3.
     * Sets ContentType for CSS/JS and a long-lived Cache-Control header.
     *
     * @param string $sourcefile
     * @param string $target_filename
     * @return boolean
     */
    private function sendFile($sourcefile, $target_filename) {
        $bucket = $this->config['CDN']['bucket'];

        $params = [
            'Bucket'       => $bucket,
            'Key'          => $target_filename,
            'SourceFile'   => $sourcefile,
        ];

        $ext = pathinfo($sourcefile, PATHINFO_EXTENSION);
        if ($ext === 'css') {
            $params['ContentType'] = 'text/css';
        } elseif ($ext === 'js') {
            $params['ContentType'] = 'text/javascript';
        }

        try {
            $this->client()->putObject($params);
            return true;
        } catch (AwsException $e) {
            $this->log[] = 'transfer error: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Walk through all objects in the bucket and delete any whose ETag
     * (MD5 for single-part uploads) is not in $excludeHashes and which
     * are older than $olderThan.
     *
     * @param string $olderThan     strtotime-compatible string, e.g. '-3 months'
     * @param array  $excludeHashes list of current MD5 hashes; these are never deleted
     * @param array  $config
     * @return int number of deleted files
     */
    public static function purge($olderThan = '-3 months', $excludeHashes = array(), $config = array()) {
        $_this          = Cdnmd5S3::getInstance($config);
        $client         = $_this->client();
        $bucket         = $_this->config['CDN']['bucket'];
        $olderThanEpoch = strtotime($olderThan);
        $deleted        = 0;

        $paginator = $client->getPaginator('ListObjectsV2', ['Bucket' => $bucket]);
        foreach ($paginator as $page) {
            foreach ($page['Contents'] ?? [] as $object) {
                // S3 ETags are the MD5 of the content for single-part uploads, wrapped in quotes
                $etag = trim($object['ETag'], '"');
                if (in_array($etag, $excludeHashes)) {
                    $_this->log[] = 'purge skipped: hash ' . $etag;
                    continue;
                }
                $lastModified = strtotime((string)$object['LastModified']);
                if ($lastModified > $olderThanEpoch) {
                    $_this->log[] = 'purge skipped: age ' . date('c', $lastModified) . ' > ' . date('c', $olderThanEpoch) . ' for ' . $object['Key'];
                    continue;
                }
                try {
                    $client->deleteObject(['Bucket' => $bucket, 'Key' => $object['Key']]);
                    $_this->log[] = 'purged file ' . $object['Key'];
                    $deleted++;
                } catch (AwsException $e) {
                    $_this->log[] = 'purge error ' . $object['Key'] . ': ' . $e->getMessage();
                }
            }
        }
        return $deleted;
    }

    /**
     * Delete a single object from S3 by its public CDN URL or object key.
     *
     * @param string $filepath  public CDN URL or object key
     * @param array  $config
     * @return boolean
     */
    public static function delete($filepath = null, $config = array()) {
        if (empty($filepath)) {
            return false;
        }
        $_this = Cdnmd5S3::getInstance($config);
        $key   = $_this->extractKey($filepath);
        try {
            $_this->client()->deleteObject(['Bucket' => $_this->config['CDN']['bucket'], 'Key' => $key]);
            return true;
        } catch (AwsException $e) {
            $_this->log[] = 'delete error: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Return metadata for an object by its public CDN URL or object key.
     *
     * @param string $filepath  public CDN URL or object key
     * @param array  $config
     * @return array|false
     */
    public static function details($filepath = null, $config = array()) {
        if (empty($filepath)) {
            return false;
        }
        $_this = Cdnmd5S3::getInstance($config);
        $key   = $_this->extractKey($filepath);

        try {
            $result = $_this->client()->headObject([
                'Bucket' => $_this->config['CDN']['bucket'],
                'Key'    => $key,
            ]);
            return [
                'name'         => $key,
                'size'         => $result['ContentLength'],
                'content_type' => $result['ContentType'],
                'last_modified' => (string)$result['LastModified'],
                'hash'         => trim($result['ETag'], '"'),
            ];
        } catch (AwsException $e) {
            return false;
        }
    }

    /**
     * Strip the configured CDN base URL from a filepath to obtain the
     * raw S3 object key.  If no base URL matches, the filepath is returned
     * as-is (assumed to already be an object key).
     *
     * @param string $filepath
     * @return string
     */
    private function extractKey($filepath) {
        foreach (['https', 'http'] as $scheme) {
            $base = $this->config['CDN'][$scheme];
            if (!empty($base) && strpos($filepath, $base) === 0) {
                return ltrim(substr($filepath, strlen($base)), '/');
            }
        }
        return $filepath;
    }

}
