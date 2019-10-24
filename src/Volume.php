<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace craft\googlecloud;

use Craft;
use craft\base\FlysystemVolume;
use craft\helpers\Assets;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use DateTime;
use Google\Auth\HttpHandler\Guzzle6HttpHandler;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use Superbalist\Flysystem\GoogleStorage\GoogleStorageAdapter;

/**
 * Class Volume
 *
 * @property mixed $settingsHtml
 * @property string $rootUrl
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class Volume extends FlysystemVolume
{
    // Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Google Cloud Storage';
    }

    // Properties
    // =========================================================================

    /**
     * @var bool Whether this is a local source or not. Defaults to false.
     */
    protected $isVolumeLocal = false;

    /**
     * @var string Subfolder to use
     */
    public $subfolder = '';

    /**
     * @var string Google Cloud project id.
     */
    public $projectId = '';

    /**
     * @var string Contents of the connection key file
     */
    public $keyFileContents = '';

    /**
     * @var string Bucket to use
     */
    public $bucket = '';

    /**
     * @var string Cache expiration period.
     */
    public $expires = '';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function rules()
    {
        $rules = parent::rules();
        $rules[] = [['bucket', 'projectId'], 'required'];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
    {
        return Craft::$app->getView()->renderTemplate('google-cloud/volumeSettings', [
            'volume' => $this,
            'periods' => array_merge(['' => ''], Assets::periodList()),
        ]);
    }

    /**
     * Get the bucket list using the specified key file contents and project id.
     *
     * @param string $projectId
     * @param string $keyFileContents
     * @return array
     * @throws \InvalidArgumentException
     */
    public static function loadBucketList(string $projectId, string $keyFileContents)
    {
        // Any region will do.
        $config = static::_buildConfigArray($projectId, $keyFileContents);

        $client = static::client($config);

        /**
         * @var $buckets Bucket[]
         */
        $buckets = $client->buckets(['projection' => 'noAcl']);

        $bucketList = [];

        foreach ($buckets as $bucket) {
            $bucketList[] = [
                'bucket' => $bucket->name(),
                'urlPrefix' => 'http://storage.googleapis.com/'.$bucket->name().'/',
            ];
        }

        return $bucketList;
    }

    /**
     * @inheritdoc
     */
    public function getRootUrl()
    {
        if (($rootUrl = parent::getRootUrl()) !== false && $this->subfolder) {
            $rootUrl .= rtrim($this->subfolder, '/').'/';
        }
        return $rootUrl;
    }

    /**
     * @inheritdoc
     */
    public function deleteDir(string $path)
    {
        $fileList = $this->getFileList($path, true);

        foreach ($fileList as $object) {
            try {
                if ($object['type'] === 'dir') {
                    $this->filesystem()->deleteDir($object['path']);
                } else {
                    $this->filesystem()->delete($object['path']);
                }
            } catch (\Throwable $exception) {
                // Even though we just listed this, the folders may or may not exist
                // Depending on whether the folder was created or a file like "folder/file.ext" was uploaded
                continue;
            }
        }

        try {
            $this->filesystem()->deleteDir($path);
        } catch (\Throwable $exception) {
            //Ignore if this was a phantom folder, too.
        }
    }


    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @return GoogleStorageAdapter
     */
    protected function createAdapter()
    {
        $config = $this->_getConfigArray();

        $client = static::client($config);
        $bucket = $client->bucket($this->bucket);

        return new GoogleStorageAdapter($client, $bucket, $this->subfolder);
    }

    /**
     * Get the Google Cloud Storage client.
     *
     * @param $config
     * @return StorageClient
     */
    protected static function client(array $config = []): StorageClient
    {
        return new StorageClient($config);
    }

    /**
     * @inheritdoc
     */
    protected function addFileMetadataToConfig(array $config): array
    {
        if (!empty($this->expires) && DateTimeHelper::isValidIntervalString($this->expires)) {
            $expires = new DateTime();
            $now = new DateTime();
            $expires->modify('+'.$this->expires);
            $diff = $expires->format('U') - $now->format('U');

            if (!isset($config['metadata'])) {
                $config['metadata'] = [];
            }
            $config['metadata']['cacheControl'] = 'public,max-age='.$diff.', must-revalidate';
        }

        return parent::addFileMetadataToConfig($config);
    }

    // Private Methods
    // =========================================================================

    /**
     * Get the config array for Google Cloud Storage clients.
     *
     * @return array
     */
    private function _getConfigArray()
    {
        $projectId = $this->projectId;
        $keyFileContents = $this->keyFileContents;

        return static::_buildConfigArray($projectId, $keyFileContents);
    }

    /**
     * Build the config array based on a project id and key file contents.
     *
     * @param string $projectId
     * @param string $keyFileContents
     * @return array
     */
    private static function _buildConfigArray(string $projectId, string $keyFileContents)
    {
        $config = [
            'projectId' => $projectId,
        ];

        if (!empty($keyFileContents)) {
            $config['keyFile'] = Json::decode($keyFileContents);
        }

        $client = Craft::createGuzzleClient();
        $handler = new Guzzle6HttpHandler($client);

        $config['httpHandler'] = $handler;
        $config['authHttpHandler'] = $handler;

        return $config;
    }
    
    /**
     * Validates the component.
     *
     * @param string[]|null $attributeNames List of attribute names that should
     * be validated. If this parameter is empty, it means any attribute listed
     * in the applicable validation rules should be validated.
     * @param bool $clearErrors Whether existing errors should be cleared before
     * performing validation
     * @return bool Whether the validation is successful without any error.
     */
    public function validate($attributeNames = null, $clearErrors = true){
        return true;  // TODO: actually implement some validation
    }
}
