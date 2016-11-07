<?php

elFinder::$netDrivers['onedrive'] = 'OneDrive';

/**
 * Simple elFinder driver for OneDrive
 * onedrive api v5.0.
 *
 * @author Dmitry (dio) Levashov
 * @author Cem (discofever)
 **/
class elFinderVolumeOneDrive extends elFinderVolumeDriver
{
    /**
     * Driver id
     * Must be started from letter and contains [a-z0-9]
     * Used as part of volume id.
     *
     * @var string
     **/
    protected $driverId = 'od';

    /**
     * @var string The base URL for API requests
     **/
    const API_URL = 'https://api.onedrive.com/v1.0/drive/items/';

    /**
     * @var string The base URL for authorization requests
     */
    const AUTH_URL = 'https://login.live.com/oauth20_authorize.srf';

    /**
     * @var string The base URL for token requests
     */
    const TOKEN_URL = 'https://login.live.com/oauth20_token.srf';

    /**
     * OneDrive service object.
     *
     * @var object
     **/
    protected $onedrive = null;

    /**
     * Directory for tmp files
     * If not set driver will try to use tmbDir as tmpDir.
     *
     * @var string
     **/
    protected $tmp = '';

    /**
     * Net mount key.
     *
     * @var string
     **/
    public $netMountKey = '';

    /**
     * Thumbnail prefix.
     *
     * @var string
     **/
    protected $tmbPrefix = '';

    /**
     * hasCache by folders.
     *
     * @var array
     **/
    protected $HasdirsCache = array();

    /**
     * Query options of API call
     * 
     * @var array
     */
    protected $queryOptions = array();

    /**
     * Constructor
     * Extend options with required fields.
     *
     * @author Dmitry (dio) Levashov
     * @author Cem (DiscoFever)
     **/
    public function __construct()
    {
        $opts = array(
            'client_id' => '',
            'client_secret' => '',
            'accessToken' => '',
            'root' => 'OneDrive.com',
            'OneDriveApiClient' => '',
            'path' => '/',
            'separator' => '/',
            'tmbPath' => '',
            'tmbURL' => '',
            'tmpPath' => '',
            'acceptedName' => '#^[^/\\?*:|"<>]*[^./\\?*:|"<>]$#',
            'rootCssClass' => 'elfinder-navbar-root-onedrive',
            'useApiThumbnail' => true,
        );
        $this->options = array_merge($this->options, $opts);
        $this->options['mimeDetect'] = 'internal';
    }

    /*********************************************************************/
    /*                        ORIGINAL FUNCTIONS                         */
    /*********************************************************************/

    /**
     * Obtains a new access token from OAuth. This token is valid for one hour.
     *
     * @param string $clientSecret The OneDrive client secret
     * @param string $code         The code returned by OneDrive after
     *                             successful log in
     * @param string $redirectUri  Must be the same as the redirect URI passed
     *                             to LoginUrl
     *
     * @throws \Exception Thrown if this Client instance's clientId is not set
     * @throws \Exception Thrown if the redirect URI of this Client instance's
     *                    state is not set
     */
    protected function _od_obtainAccessToken($client_id, $client_secret, $code)
    {
        if (null === $client_id) {
            return 'The client ID must be set to call obtainAccessToken()';
        }

        if (null === $client_secret) {
            return 'The client Secret must be set to call obtainAccessToken()';
        }

        $url = self::TOKEN_URL;

        $curl = curl_init();

        $fields = http_build_query(
                array(
                        'client_id' => $client_id,
                        'redirect_uri' => elFinder::getConnectorUrl(),
                        'client_secret' => $client_secret,
                        'code' => $code,
                        'grant_type' => 'authorization_code',
                )
                );

        curl_setopt_array($curl, array(
                // General options.
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_AUTOREFERER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $fields,

                CURLOPT_HTTPHEADER => array(
                        'Content-Length: '.strlen($fields),
                ),

                // SSL options.
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_URL => $url,
        ));

        $result = curl_exec($curl);

        if (false === $result) {
            if (curl_errno($curl)) {
                throw new \Exception('curl_setopt_array() failed: '
                        .curl_error($curl));
            } else {
                throw new \Exception('curl_setopt_array(): empty response');
            }
        }
        curl_close($curl);

        $decoded = json_decode($result);

        if (null === $decoded) {
            throw new \Exception('json_decode() failed');
        }

        $token = $this->session->get('OneDriveTokens');
        $token->redirect_uri = null;

        $token->token = (object) array(
                'obtained' => time(),
                'data' => $decoded,
        );

        return $token;
    }

    /**
     * Get token and auto refresh.
     *
     * @param object $client OneDrive API client
     *
     * @return true|string error message
     */
    protected function _od_refreshToken($onedrive)
    {
        try {
            if (0 >= ($onedrive->token->obtained + $onedrive->token->data->expires_in - time())) {
                if (null === $this->options['client_id']) {
                    $this->options['client_id'] = ELFINDER_ONEDRIVE_CLIENTID;
                }

                if (null === $this->options['client_secret']) {
                    $this->options['client_secret'] = ELFINDER_ONEDRIVE_CLIENTSECRET;
                }

                if (null === $this->onedrive->token->data->refresh_token) {
                    return 'The refresh token is not set or no permission for \'wl.offline_access\' was given to renew the token';
                }

                $url = self::TOKEN_URL;

                $curl = curl_init();

                curl_setopt_array($curl, array(
                        // General options.
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_AUTOREFERER => true,
                        CURLOPT_POST => true, // i am sending post data
                        CURLOPT_POSTFIELDS => 'client_id='.urlencode($this->options['client_id'])
                        .'&client_secret='.urlencode($this->options['client_secret'])
                        .'&grant_type=refresh_token'
                        .'&refresh_token='.urlencode($onedrive->token->data->refresh_token),

                        // SSL options.
                        CURLOPT_SSL_VERIFYHOST => 2,
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_URL => $url,
                ));

                $result = curl_exec($curl);

                if (false === $result) {
                    if (curl_errno($curl)) {
                        throw new \Exception('curl_setopt_array() failed: '.curl_error($curl));
                    } else {
                        throw new \Exception('curl_setopt_array(): empty response');
                    }
                }
                curl_close($curl);

                $decoded = json_decode($result);

                if (null === $decoded) {
                    throw new \Exception('json_decode() failed');
                }

                $onedrive->token = (object) array(
                        'obtained' => time(),
                        'data' => $decoded,
                );

                $this->session->set('OneDriveAuthTokens', $onedrive);
                $this->options['accessToken'] = json_encode($this->session->get('OneDriveAuthTokens'));
                $this->onedrive = json_decode($this->options['accessToken']);
            }
        } catch (Exception $e) {
            return $e->getMessage();
        }

        return true;
    }

    /**
     * Get Parent ID, Item ID, Parent Path as an array from path.
     *
     * @param string $path
     *
     * @return array
     */
    protected function _od_splitPath($path)
    {
        $path = trim($path, '/');
        $pid = '';
        if ($path === '') {
            $id = 'root';
            $parent = '';
        } else {
            $paths = explode('/', trim($path, '/'));
            $id = array_pop($paths);
            if ($paths) {
                $parent = '/'.implode('/', $paths);
                $pid = array_pop($paths);
            } else {
                $pid = 'root';
                $parent = '/';
            }
        }

        return array($pid, $id, $parent);
    }

    /**
     * Creates a base cURL object which is compatible with the OneDrive API.
     *
     * @return resource A compatible cURL object
     */
    protected function _od_prepareCurl($url = null)
    {
        $curl = curl_init($url);

        $defaultOptions = array(
                // General options.
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_AUTOREFERER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER => array(
                        'Content-Type: application/json',
                        'Authorization: Bearer '.$this->onedrive->token->data->access_token,
                ),
        );

        curl_setopt_array($curl, $defaultOptions);

        return $curl;
    }

    /**
     * Creates a base cURL object which is compatible with the OneDrive API.
     *
     * @param string $path The path of the API call (eg. me/skydrive)
     *
     * @return resource A compatible cURL object
     */
    protected function _od_createCurl($path, $contents = false)
    {
        elfinder::extendTimeLimit();
        $curl = $this->_od_prepareCurl($path);

        if ($contents) {
            $res = curl_exec($curl);
            curl_close($curl);
        } else {
            $result = json_decode(curl_exec($curl));
            curl_close($curl);
            if (isset($result->value)) {
                $res = $result->value;
                unset($result->value);
                $result = (array) $result;
                if (!empty($result['@odata.nextLink'])) {
                    $nextRes = $this->_od_createCurl($result['@odata.nextLink'], false);
                    if (is_array($nextRes)) {
                        $res = array_merge($res, $nextRes);
                    }
                }
            } else {
                $res = $result;
            }
        }

        return $res;
    }

    /**
     * Drive query and fetchAll.
     *
     * @param string $sql
     *
     * @return object|array
     */
    protected function _od_query($itemId, $fetch_self = false, $recursive = false, $options = array())
    {
        $result = array();

        if (null === $itemId) {
            $itemId = 'root';
        }

        if ($fetch_self == true) {
            $path = $itemId;
        } else {
            $path = $itemId.'/children';
        }

        if (isset($options['query'])) {
            $parts = array();
            foreach ($options['query'] as $key => $val) {
                $parts[] = rawurlencode($key).'='.rawurlencode($val);
            }
            $path .= '?'.implode('&', $parts);
        }

        $url = self::API_URL.$path;

        $res = $this->_od_createCurl($url);
        if (!$fetch_self && $recursive && is_array($res)) {
            foreach ($res as $file) {
                $result[] = $file;
                if (!empty($file->folder)) {
                    $result = array_merge($result, $this->_od_query($file->id, false, true, $options));
                }
            }
        } else {
            $result = $res;
        }

        return isset($result->error) ? array() : $result;
    }

    /**
     * Parse line from onedrive metadata output and return file stat (array).
     *
     * @param string $raw line from ftp_rawlist() output
     *
     * @return array
     *
     * @author Dmitry Levashov
     **/
    protected function _od_parseRaw($raw)
    {
        $stat = array();

        $folder = isset($raw->folder) ? $raw->folder : null;

        $stat['rev'] = isset($raw->id) ? $raw->id : 'root';
        $stat['name'] = $raw->name;
        if (isset($raw->lastModifiedDateTime)) {
            $stat['ts'] = strtotime($raw->lastModifiedDateTime);
        }

        if ($folder) {
            $stat['mime'] = 'directory';
            if (empty($folder->childCount)) {
                $stat['dirs'] = 0;
            }
        } else {
            $stat['mime'] = isset($raw->file->mimeType) ? $raw->file->mimeType : self::mimetypeInternalDetect($raw->name);
            $stat['size'] = (int) $raw->size;
            $stat['url'] = '1';
            if (isset($raw->image) && $img = $raw->image) {
                isset($img->width) ? $stat['width'] = $img->width : $stat['width'] = 0;
                isset($img->height) ? $stat['height'] = $img->height : $stat['height'] = 0;
            }
            if (!empty($raw->thumbnails)) {
                if ($raw->thumbnails[0]->small->url) {
                    $stat['tmb'] = substr($raw->thumbnails[0]->small->url, 8); // remove "https://"
                }
            }
            $stat['url'] = '1';
        }

        return $stat;
    }

    /**
     * Get raw data(onedrive metadata) from OneDrive.
     *
     * @param string $path
     *
     * @return array|object onedrive metadata
     */
    protected function _od_getFileRaw($path)
    {
        list(, $itemId) = $this->_od_splitPath($path);
        try {
            $res = $this->_od_query($itemId, true, false, $this->queryOptions);

            return $res;
        } catch (Exception $e) {
            return array();
        }
    }

    /**
     * Get thumbnail from OneDrive.com.
     *
     * @param string $path
     * @param string $size
     *
     * @return string | boolean
     */
    protected function _od_getThumbnail($path)
    {
        list(, $itemId) = $this->_od_splitPath($path);

        try {
            $url = self::API_URL.$itemId.'/thumbnails/0/medium/content';

            return $this->_od_createCurl($url, $contents = true);
        } catch (Exception $e) {
            return false;
        }
    }

    /*********************************************************************/
    /*                        OVERRIDE FUNCTIONS                         */
    /*********************************************************************/

    /**
     * Prepare
     * Call from elFinder::netmout() before volume->mount().
     *
     * @return array
     *
     * @author Naoki Sawada
     * @author Raja Sharma updating for OneDrive
     **/
    public function netmountPrepare($options)
    {
        if (empty($options['client_id']) && defined('ELFINDER_ONEDRIVE_CLIENTID')) {
            $options['client_id'] = ELFINDER_ONEDRIVE_CLIENTID;
        }
        if (empty($options['client_secret']) && defined('ELFINDER_ONEDRIVE_CLIENTSECRET')) {
            $options['client_secret'] = ELFINDER_ONEDRIVE_CLIENTSECRET;
        }

        if (isset($options['pass']) && $options['pass'] === 'reauth') {
            $options['user'] = 'init';
            $options['pass'] = '';
            $this->session->remove('OneDriveTokens');
            $this->session->remove('OneDriveAuthTokens');
        }

        try {
            if (empty($options['client_id']) || empty($options['client_secret'])) {
                return array('exit' => true, 'body' => '{msg:errNetMountNoDriver}');
            }

            $onedrive = array(
                'client_id' => $options['client_id'],
            );

            if (isset($_GET['code'])) {
                try {
                    if ($_GET['protocol'] !== 'onedrive') {
                        $callback_url = elFinder::getConnectorUrl().'?cmd=netmount&protocol=onedrive&host=1&code='.$_GET['code'];
                        header('Location: '.$callback_url);
                        exit();
                    }

                    $onedrive = array(
                        'client_id' => $options['client_id'],
                    );

                    // Persist the OneDrive client' state for next API requests
                    $onedrive = array(
                        'client_id' => $options['client_id'],
                        // Restore the previous state while instantiating this client to proceed in
                        // obtaining an access token
                        'state' => $this->session->get('OneDriveTokens'),
                    );

                    // Obtain the token using the code received by the OneDrive API

                    // Persist the OneDrive client' state for next API requests

                    $this->session->set('OneDriveAuthTokens',
                                        $this->_od_obtainAccessToken($options['client_id'], $options['client_secret'], $_GET['code']));

                    $out = array(
                            'node' => 'elfinder',
                            'json' => '{"protocol": "onedrive", "mode": "done", "reset": 1}',
                            'bind' => 'netmount',

                    );

                    return array('exit' => 'callback', 'out' => $out);
                } catch (Exception $e) {
                    return $e->getMessage();
                }
            }

            if ($options['user'] === 'init') {
                if (empty($_GET['code']) && empty($_GET['pass']) && empty($this->session->get('OneDriveAuthTokens'))) {
                    $cdata = '';
                    $innerKeys = array('cmd', 'host', 'options', 'pass', 'protocol', 'user');
                    $this->ARGS = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
                    foreach ($this->ARGS as $k => $v) {
                        if (!in_array($k, $innerKeys)) {
                            $cdata .= '&'.$k.'='.rawurlencode($v);
                        }
                    }
                    if (empty($options['url'])) {
                        $options['url'] = elFinder::getConnectorUrl();
                    }
                    $callback = $options['url']
                        .'?cmd=netmount&protocol=onedrive&host=onedrive.com&user=init&pass=return&node='.$options['id'].$cdata;

                    try {
                        // Instantiates a OneDrive client bound to your OneDrive application
                        $this->onedrive = array(
                            'client_id' => $options['client_id'],
                        );

                        $offline = '';
                        // Gets a log in URL with sufficient privileges from the OneDrive API
                        if (!empty($options['offline'])) {
                            $offline = 'wl.offline_access';
                        }

                        $redirect_uri = $options['url'].'/netmount/onedrive/1';
                        $url = self::AUTH_URL
                            .'?client_id='.urlencode($options['client_id'])
                            .'&scope='.urlencode('wl.signin '.$offline.' wl.skydrive_update')
                            .'&response_type=code'
                            .'&redirect_uri='.urlencode($redirect_uri);

                        // Persist the OneDrive client' state for next API requests
                        $this->session->set('OneDriveTokens',  (object) array(
                                                                        'redirect_uri' => $redirect_uri,
                                                                        'token' => null,
                                                                    ));

                        $url .= '&oauth_callback='.rawurlencode($callback);
                    } catch (Exception $e) {
                        return array('exit' => true, 'body' => '{msg:errAccess}');
                    }

                    $html = '<input id="elf-volumedriver-onedrive-host-btn" class="ui-button ui-widget ui-state-default ui-corner-all ui-button-text-only" value="{msg:btnApprove}" type="button" onclick="window.open(\''.$url.'\')">';
                    $html .= '<script>
							$("#'.$options['id'].'").elfinder("instance").trigger("netmount", {protocol: "onedrive", mode: "makebtn"});
							</script>';

                    return array('exit' => true, 'body' => $html);
                } else {
                    $this->onedrive = $this->session->get('OneDriveAuthTokens');
                    $result = $this->_od_query('root', false, false, array(
                        'query' => array(
                            'select' => 'id,name',
                            'filter' => 'folder ne null',
                        ),
                    ));
                    $folders = [];

                    foreach ($result as $res) {
                        $folders[$res->id] = $res->name;
                    }

                    natcasesort($folders);
                    $folders = ['root' => 'My OneDrive'] + $folders;
                    $folders = json_encode($folders);

                    $json = '{"protocol": "onedrive", "mode": "done", "folders": '.$folders.'}';
                    $html = 'OneDrive.com';
                    $html .= '<script>
							$("#'.$options['id'].'").elfinder("instance").trigger("netmount", '.$json.');
							</script>';

                    return array('exit' => true, 'body' => $html);
                }
            }
        } catch (Exception $e) {
            return array('exit' => true, 'body' => '{msg:errNetMountNoDriver}');
        }

        if ($this->session->get('OneDriveAuthTokens')) {
            $options['accessToken'] = json_encode($this->session->get('OneDriveAuthTokens'));
        }
        unset($options['user'], $options['pass']);

        return $options;
    }

    /**
     * process of on netunmount
     * Drop `onedrive` & rm thumbs.
     *
     * @param array $options
     *
     * @return bool
     */
    public function netunmount($netVolumes, $key)
    {
        if ($tmbs = glob(rtrim($this->options['tmbPath'], '\\/').DIRECTORY_SEPARATOR.$this->tmbPrefix.'*.png')) {
            foreach ($tmbs as $file) {
                unlink($file);
            }
        }

        return true;
    }

    /*********************************************************************/
    /*                        INIT AND CONFIGURE                         */
    /*********************************************************************/

    /**
     * Prepare FTP connection
     * Connect to remote server and check if credentials are correct, if so, store the connection id in $ftp_conn.
     *
     * @return bool
     *
     * @author Dmitry (dio) Levashov
     * @author Cem (DiscoFever)
     **/
    protected function init()
    {
        if (!$this->options['accessToken']) {
            return $this->setError('Required options undefined.');
        }

        // make net mount key
        $this->netMountKey = md5(implode('-', array('onedrive', $this->options['path'])));

        if (!$this->onedrive) {
            try {
                $this->onedrive = json_decode($this->options['accessToken']);
                if (true !== ($res = $this->_od_refreshToken($this->onedrive))) {
                    return $this->setError($res);
                }
            } catch (InvalidArgumentException $e) {
                return $this->setError($e->getMessage());
            }
            try {
                $this->onedrive = json_decode($this->options['accessToken']);
            } catch (Exception $e) {
                return $this->setError($e->getMessage());
            }
        }

        if (!$this->onedrive) {
            return $this->setError('OAuth extension not loaded.');
        }

        // normalize root path
        if ($this->options['path'] == 'root') {
            $this->options['path'] = '/';
        }

        $this->root = $this->options['path'] = $this->_normpath($this->options['path']);

        $this->options['root'] == '' ? $this->options['root'] = 'OneDrive.com' : $this->options['root'];

        if (empty($this->options['alias'])) {
            $this->options['alias'] = ($this->options['path'] === '/') ? $this->options['root'] :
                                      $this->_od_query(basename($this->options['path']), $fetch_self = true)->name.'@OneDrive';
        }

        $this->rootName = $this->options['alias'];

        $this->tmbPrefix = 'onedrive'.base_convert($this->netMountKey, 10, 32);

        if (!empty($this->options['tmpPath'])) {
            if ((is_dir($this->options['tmpPath']) || mkdir($this->options['tmpPath'])) && is_writable($this->options['tmpPath'])) {
                $this->tmp = $this->options['tmpPath'];
            }
        }

        if (!$this->tmp && is_writable($this->options['tmbPath'])) {
            $this->tmp = $this->options['tmbPath'];
        }
        if (!$this->tmp && ($tmp = elFinder::getStaticVar('commonTempPath'))) {
            $this->tmp = $tmp;
        }

        // This driver dose not support `syncChkAsTs`
        $this->options['syncChkAsTs'] = false;

        // 'lsPlSleep' minmum 10 sec
        $this->options['lsPlSleep'] = max(10, $this->options['lsPlSleep']);

        $this->queryOptions = array(
            'query' => array(
                'select' => 'id,name,lastModifiedDateTime,file,folder,size,image',
            ),
        );

        if ($this->options['useApiThumbnail']) {
            $this->options['tmbURL'] = 'https://';
            $this->queryOptions['query']['expand'] = 'thumbnails(select=small)';
        }

        return true;
    }

    /**
     * Configure after successfull mount.
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function configure()
    {
        parent::configure();

        $this->disabled[] = 'archive';
        $this->disabled[] = 'extract';
    }

    /*********************************************************************/
    /*                               FS API                              */
    /*********************************************************************/

    /**
     * Close opened connection.
     *
     * @author Dmitry (dio) Levashov
     **/
    public function umount()
    {
    }

    protected function isNameExists($path)
    {
        list($pid, $name) = $this->_od_splitPath($path);

        return (bool) $this->_od_query($pid.'/children/'.rawurlencode($name).'?select=id', true);
    }

    /**
     * Cache dir contents.
     *
     * @param string $path dir path
     *
     * @author Dmitry Levashov
     **/
    protected function cacheDir($path)
    {
        $this->dirsCache[$path] = array();
        $this->subdirsCache[$path] = false;

        list(, $itemId) = $this->_od_splitPath($path);

        $res = $this->_od_query($itemId, false, false, $this->queryOptions);

        $path == '/' ? $mountPath = '/' : $mountPath = $this->_normpath($path.'/');

        if ($res) {
            foreach ($res as $raw) {
                if ($stat = $this->_od_parseRaw($raw)) {
                    $stat = $this->updateCache($mountPath.$raw->id, $stat);
                    if (empty($stat['hidden']) && $path !== $mountPath.$raw->id) {
                        if ($stat['mime'] === 'directory') {
                            $this->subdirsCache[$path] = true;
                        }
                        $this->dirsCache[$path][] = $mountPath.$raw->id;
                    }
                }
            }
        }

        return $this->dirsCache[$path];
    }

    /**
     * Copy file/recursive copy dir only in current volume.
     * Return new file path or false.
     *
     * @param string $src  source path
     * @param string $dst  destination dir path
     * @param string $name new file name (optionaly)
     *
     * @return string|false
     *
     * @author Dmitry (dio) Levashov
     * @author Naoki Sawada
     **/
    protected function copy($src, $dst, $name)
    {
        $itemId = '';
        if ($this->options['copyJoin']) {
            $test = $this->joinPathCE($dst, $name);
            if ($testStat = $this->isNameExists($test)) {
                $this->remove($test);
            }
        }

        $path = $this->_copy($src, $dst, $name);

        return $path
            ? $path
            : $this->setError(elFinder::ERROR_COPY, $this->_path($src));
    }

    /**
     * Remove file/ recursive remove dir.
     *
     * @param string $path  file path
     * @param bool   $force try to remove even if file locked
     *
     * @return bool
     *
     * @author Dmitry (dio) Levashov
     * @author Naoki Sawada
     **/
    protected function remove($path, $force = false)
    {
        $stat = $this->stat($path);
        $stat['realpath'] = $path;
        $this->rmTmb($stat);
        $this->clearcache();

        if (empty($stat)) {
            return $this->setError(elFinder::ERROR_RM, $this->_path($path), elFinder::ERROR_FILE_NOT_FOUND);
        }

        if (!$force && !empty($stat['locked'])) {
            return $this->setError(elFinder::ERROR_LOCKED, $this->_path($path));
        }

        if ($stat['mime'] == 'directory') {
            if (!$this->_rmdir($path)) {
                return $this->setError(elFinder::ERROR_RM, $this->_path($path));
            }
        } else {
            if (!$this->_unlink($path)) {
                return $this->setError(elFinder::ERROR_RM, $this->_path($path));
            }
        }

        $this->removed[] = $stat;

        return true;
    }

    /**
     * Create thumnbnail and return it's URL on success.
     *
     * @param string $path file path
     * @param string $mime file mime type

     * @return string|false
     *
     * @author Dmitry (dio) Levashov
     * @author Naoki Sawada
     **/
    protected function createTmb($path, $stat)
    {
        if (!$stat || !$this->canCreateTmb($path, $stat)) {
            return false;
        }

        $name = $this->tmbname($stat);
        $tmb = $this->tmbPath.DIRECTORY_SEPARATOR.$name;

        // copy image into tmbPath so some drivers does not store files on local fs
        if (!$data = $this->_od_getThumbnail($path)) {
            return false;
        }
        if (!file_put_contents($tmb, $data)) {
            return false;
        }

        $result = false;

        $tmbSize = $this->tmbSize;

        if (($s = getimagesize($tmb)) == false) {
            return false;
        }

        /* If image smaller or equal thumbnail size - just fitting to thumbnail square */
        if ($s[0] <= $tmbSize && $s[1] <= $tmbSize) {
            $result = $this->imgSquareFit($tmb, $tmbSize, $tmbSize, 'center', 'middle', $this->options['tmbBgColor'], 'png');
        } else {
            if ($this->options['tmbCrop']) {

                /* Resize and crop if image bigger than thumbnail */
                if (!(($s[0] > $tmbSize && $s[1] <= $tmbSize) || ($s[0] <= $tmbSize && $s[1] > $tmbSize)) || ($s[0] > $tmbSize && $s[1] > $tmbSize)) {
                    $result = $this->imgResize($tmb, $tmbSize, $tmbSize, true, false, 'png');
                }

                if (($s = getimagesize($tmb)) != false) {
                    $x = $s[0] > $tmbSize ? intval(($s[0] - $tmbSize) / 2) : 0;
                    $y = $s[1] > $tmbSize ? intval(($s[1] - $tmbSize) / 2) : 0;
                    $result = $this->imgCrop($tmb, $tmbSize, $tmbSize, $x, $y, 'png');
                }
            } else {
                $result = $this->imgResize($tmb, $tmbSize, $tmbSize, true, true, 'png');
            }

            $result = $this->imgSquareFit($tmb, $tmbSize, $tmbSize, 'center', 'middle', $this->options['tmbBgColor'], 'png');
        }

        if (!$result) {
            unlink($tmb);

            return false;
        }

        return $name;
    }

    /**
     * Return thumbnail file name for required file.
     *
     * @param array $stat file stat
     *
     * @return string
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function tmbname($stat)
    {
        return $this->tmbPrefix.$stat['rev'].$stat['ts'].'.png';
    }

    /**
     * Return content URL.
     *
     * @param string $hash    file hash
     * @param array  $options options
     *
     * @return array
     *
     * @author Naoki Sawada
     **/
    public function getContentUrl($hash, $options = array())
    {
        $res = '';
        if (($file = $this->file($hash)) == false || !$file['url'] || $file['url'] == 1) {
            $path = $this->decode($hash);

            list(, $itemId) = $this->_od_splitPath($path);
            try {
                $url = self::API_URL.$itemId.'/action.createLink';
                $data = (object) array(
                    'type' => 'embed',
                    'scope' => 'anonymous',
                );
                $curl = $this->_od_prepareCurl($url);
                curl_setopt_array($curl, array(
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($data),
                ));

                $result = curl_exec($curl);
                curl_close($curl);
                if ($result) {
                    $result = json_decode($result);
                    if (isset($result->link)) {
                        list(, $res) = explode('?', $result->link->webUrl);
                        $res = 'https://onedrive.live.com/download.aspx?'.$res;
                    }
                }
            } catch (Exception $e) {
                $res = '';
            }
        }

        return $res;
    }

    /*********************** paths/urls *************************/

    /**
     * Return parent directory path.
     *
     * @param string $path file path
     *
     * @return string
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function _dirname($path)
    {
        list(, , $dirname) = $this->_od_splitPath($path);

        return $dirname;
    }

    /**
     * Return file name.
     *
     * @param string $path file path
     *
     * @return string
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function _basename($path)
    {
        return basename($path);
    }

    /**
     * Join dir name and file name and retur full path.
     *
     * @param string $dir
     * @param string $name
     *
     * @return string
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function _joinPath($dir, $name)
    {
        return $this->_normpath($dir.'/'.$name);
    }

    /**
     * Return normalized path, this works the same as os.path.normpath() in Python.
     *
     * @param string $path path
     *
     * @return string
     *
     * @author Troex Nevelin
     **/
    protected function _normpath($path)
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $path);
        }
        $path = '/'.ltrim($path, '/');

        return $path;
    }

    /**
     * Return file path related to root dir.
     *
     * @param string $path file path
     *
     * @return string
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function _relpath($path)
    {
        return $path;
    }

    /**
     * Convert path related to root dir into real path.
     *
     * @param string $path file path
     *
     * @return string
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function _abspath($path)
    {
        return $path;
    }

    /**
     * Return fake path started from root dir.
     *
     * @param string $path file path
     *
     * @return string
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function _path($path)
    {
        return $this->rootName.$this->_normpath(substr($path, strlen($this->root)));
    }

    /**
     * Return true if $path is children of $parent.
     *
     * @param string $path   path to check
     * @param string $parent parent path
     *
     * @return bool
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function _inpath($path, $parent)
    {
        return $path == $parent || strpos($path, $parent.'/') === 0;
    }

    /***************** file stat ********************/
    /**
     * Return stat for given path.
     * Stat contains following fields:
     * - (int)    size    file size in b. required
     * - (int)    ts      file modification time in unix time. required
     * - (string) mime    mimetype. required for folders, others - optionally
     * - (bool)   read    read permissions. required
     * - (bool)   write   write permissions. required
     * - (bool)   locked  is object locked. optionally
     * - (bool)   hidden  is object hidden. optionally
     * - (string) alias   for symlinks - link target path relative to root path. optionally
     * - (string) target  for symlinks - link target path. optionally.
     *
     * If file does not exists - returns empty array or false.
     *
     * @param string $path file path
     *
     * @return array|false
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function _stat($path)
    {
        if ($raw = $this->_od_getFileRaw($path)) {
            return $this->_od_parseRaw($raw);
        }

        return false;
    }

    /**
     * Return true if path is dir and has at least one childs directory.
     *
     * @param string $path dir path
     *
     * @return bool
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function _subdirs($path)
    {
        list(, $itemId) = $this->_od_splitPath($path);

        return (bool) $this->_od_query($itemId, false, false, array(
            'query' => array(
                'top' => 1,
                'select' => 'id',
                'filter' => 'folder ne null',
            ),
        ));
    }

    /**
     * Return object width and height
     * Ususaly used for images, but can be realize for video etc...
     *
     * @param string $path file path
     * @param string $mime file mime type
     *
     * @return string
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function _dimensions($path, $mime)
    {
        if (strpos($mime, 'image') !== 0) {
            return '';
        }

        $cache = $this->_od_getFileRaw($path);

        if ($cache && $img = $cache->image) {
            if (isset($img->width) && isset($img->height)) {
                return $img->width.'x'.$img->height;
            }
        }

        $ret = '';
        if ($work = $this->getWorkFile($path)) {
            if ($size = @getimagesize($work)) {
                $cache['width'] = $size[0];
                $cache['height'] = $size[1];
                $ret = $size[0].'x'.$size[1];
            }
        }
        is_file($work) && @unlink($work);

        return $ret;
    }

    /******************** file/dir content *********************/

    /**
     * Return files list in directory.
     *
     * @param string $path dir path
     *
     * @return array
     *
     * @author Dmitry (dio) Levashov
     * @author Cem (DiscoFever)
     **/
    protected function _scandir($path)
    {
        return isset($this->dirsCache[$path])
            ? $this->dirsCache[$path]
            : $this->cacheDir($path);
    }

    /**
     * Open file and return file pointer.
     *
     * @param string $path  file path
     * @param bool   $write open file for writing
     *
     * @return resource|false
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function _fopen($path, $mode = 'rb')
    {
        if (($mode == 'rb' || $mode == 'r')) {
            try {
                list(, $itemId) = $this->_od_splitPath($path);
                $url = self::API_URL.$itemId.'/content';

                $contents = $this->_od_createCurl($url, $contents = true);

                $fp = tmpfile();
                fputs($fp, $contents);
                rewind($fp);

                return $fp;
            } catch (Exception $e) {
                return false;
            }
        }

        if ($this->tmp) {
            $contents = $this->_getContents($path);

            if ($contents === false) {
                return false;
            }

            if ($local = $this->getTempFile($path)) {
                if (file_put_contents($local, $contents, LOCK_EX) !== false) {
                    return @fopen($local, $mode);
                }
            }
        }

        return false;
    }

    /**
     * Close opened file.
     *
     * @param resource $fp file pointer
     *
     * @return bool
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function _fclose($fp, $path = '')
    {
        fclose($fp);
        if ($path) {
            unlink($this->getTempFile($path));
        }
    }

    /********************  file/dir manipulations *************************/

    /**
     * Create dir and return created dir path or false on failed.
     *
     * @param string $path parent dir path
     * @param string $name new directory name
     *
     * @return string|bool
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function _mkdir($path, $name)
    {
        $namePath = $this->_joinPath($path, $name);
        list($parentId) = $this->_od_splitPath($namePath);

        try {
            $properties = array(
                'name' => (string) $name,
                'folder' => (object) array(),
            );

            $data = (object) $properties;

            $url = self::API_URL.$parentId.'/children';

            $curl = $this->_od_prepareCurl($url);

            curl_setopt_array($curl, array(
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
            ));

            //create the Folder in the Parent
            $result = curl_exec($curl);
            curl_close($curl);
            $folder = json_decode($result);

            return $this->_joinPath($path, $folder->id);
        } catch (Exception $e) {
            return $this->setError('OneDrive error: '.$e->getMessage());
        }
    }

    /**
     * Create file and return it's path or false on failed.
     *
     * @param string $path parent dir path
     * @param string $name new file name
     *
     * @return string|bool
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function _mkfile($path, $name)
    {
        return $this->_save(tmpfile(), $path, $name, array());
    }

    /**
     * Create symlink. FTP driver does not support symlinks.
     *
     * @param string $target link target
     * @param string $path   symlink path
     *
     * @return bool
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function _symlink($target, $path, $name)
    {
        return false;
    }

    /**
     * Copy file into another file.
     *
     * @param string $source    source file path
     * @param string $targetDir target directory path
     * @param string $name      new file name
     *
     * @return bool
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function _copy($source, $targetDir, $name)
    {
        $path = $this->_joinPath($targetDir, $name);

        try {
            //Set the Parent id
            list(, $parentId) = $this->_od_splitPath($targetDir);
            list(, $itemId) = $this->_od_splitPath($source);

            $url = self::API_URL.$itemId.'/action.copy';

            $properties = array(
                'name' => (string) $name,
            );
            if ($parentId === 'root') {
                $properties['parentReference'] = (object) array('path' => '/drive/root:');
            } else {
                $properties['parentReference'] = (object) array('id' => (string) $parentId);
            }
            $data = (object) $properties;
            $curl = $this->_od_prepareCurl($url);
            curl_setopt_array($curl, array(
                CURLOPT_POST => true,
                CURLOPT_HEADER => true,
                CURLOPT_HTTPHEADER => array(
                      'Content-Type: application/json',
                       'Authorization: Bearer '.$this->onedrive->token->data->access_token,
                    'Prefer: respond-async',
                ),
                CURLOPT_POSTFIELDS => json_encode($data),
            ));
            $result = curl_exec($curl);
            curl_close($curl);

            $res = false;
            if (preg_match('/Location: (.+)/', $result, $m)) {
                $monUrl = trim($m[1]);
                while (!$res) {
                    usleep(200000);
                    $curl = $this->_od_prepareCurl($monUrl);
                    $state = json_decode(curl_exec($curl));
                    curl_close($curl);
                    if (isset($state->status)) {
                        if ($state->status === 'failed') {
                            break;
                        } else {
                            continue;
                        }
                    }
                    $res = $state;
                }
            }

            if ($res && isset($res->id)) {
                return $this->_joinPath($targetDir, $res->id);
            }

            return false;
        } catch (Exception $e) {
            return $this->setError('OneDrive error: '.$e->getMessage());
        }

        return true;
    }

    /**
     * Move file into another parent dir.
     * Return new file path or false.
     *
     * @param string $source source file path
     * @param string $target target dir path
     * @param string $name   file name
     *
     * @return string|bool
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function _move($source, $targetDir, $name)
    {
        try {
            list(, $targetParentId) = $this->_od_splitPath($targetDir);
            list($sourceParentId, $itemId, $srcParent) = $this->_od_splitPath($source);

            $srcFile = $this->stat($source);
            $properties = array(
                    'name' => (string) $name,
                );
            if ($targetParentId !== $sourceParentId) {
                $properties['parentReference'] = (object) array('id' => (string) $targetParentId);
            }

            $url = self::API_URL.$itemId;
            $data = (object) $properties;

            $curl = $this->_od_prepareCurl($url);

            curl_setopt_array($curl, array(
                    CURLOPT_CUSTOMREQUEST => 'PATCH',
                    CURLOPT_POSTFIELDS => json_encode($data),
                ));

            $result = json_decode(curl_exec($curl));
            curl_close($curl);
            if ($result && isset($result->id)) {
                return $targetDir.'/'.$result->id;
            } else {
                return false;
            }
        } catch (Exception $e) {
            return $this->setError('OneDrive error: '.$e->getMessage());
        }

        return false;
    }

    /**
     * Remove file.
     *
     * @param string $path file path
     *
     * @return bool
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function _unlink($path)
    {
        $stat = $this->stat($path);
        try {
            list(, $itemId) = $this->_od_splitPath($path);

            $url = self::API_URL.$itemId;

            $curl = $this->_od_prepareCurl($url);
            curl_setopt_array($curl, array(
                CURLOPT_CUSTOMREQUEST => 'DELETE',
            ));

            //unlink or delete File or Folder in the Parent
            $result = curl_exec($curl);
            curl_close($curl);
        } catch (Exception $e) {
            return $this->setError('OneDrive error: '.$e->getMessage());
        }

        return true;
    }

    /**
     * Remove dir.
     *
     * @param string $path dir path
     *
     * @return bool
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function _rmdir($path)
    {
        if ($this->_unlink($path)) {
            list(, $id) = $this->_od_splitPath($path);

            return true;
        }

        return false;
    }

    /**
     * Create new file and write into it from file pointer.
     * Return new file path or false on error.
     *
     * @param resource $fp   file pointer
     * @param string   $dir  target dir path
     * @param string   $name file name
     * @param array    $stat file stat (required by some virtual fs)
     *
     * @return bool|string
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function _save($fp, $path, $name, $stat)
    {
        $itemId = '';
        if ($name === '') {
            list($parentId, $itemId, $parent) = $this->_od_splitPath($path);
        } else {
            if ($stat && isset($stat['name'])) {
                $name = $stat['name'];
            }
            list(, $parentId) = $this->_od_splitPath($path);
            $parent = $path;
        }

        try {
            //Create or Update a file
            if (is_resource($fp)) {
                $stream = $fp;
            } else {
                $options = array(
                    'stream_back_end' => 'php://temp',
                );

                $stream = fopen($options['stream_back_end'], 'rw+b');

                if (false === $stream) {
                    throw new \Exception('fopen() failed');
                }

                if (false === fwrite($stream, $fp)) {
                    fclose($stream);
                    throw new \Exception('fwrite() failed');
                }

                if (!rewind($stream)) {
                    fclose($stream);
                    throw new \Exception('rewind() failed');
                }
            }

            $params = array('overwrite' => 'true');
            $query = http_build_query($params);

            if ($itemId === '') {
                $url = self::API_URL.$parentId.'/children/'.rawurlencode($name).'/content?'.$query;
            } else {
                $url = self::API_URL.$itemId.'/content?'.$query;
            }
            $curl = $this->_od_prepareCurl();
            $stats = fstat($stream);

            $headers = array(
                'Content-Type: application/json',
                'Authorization: Bearer '.$this->onedrive->token->data->access_token,
            );

            $options = array(
                CURLOPT_URL => $url,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_PUT => true,
                CURLOPT_INFILE => $stream,
            );
            // Size
            if ($stats[7]) {
                $options[CURLOPT_INFILESIZE] = $stats[7];
            }

            curl_setopt_array($curl, $options);

            //create or update File in the Target
            $file = json_decode(curl_exec($curl));
            curl_close($curl);

            if (!is_resource($fp)) {
                fclose($stream);
            }
        } catch (Exception $e) {
            return $this->setError('OneDrive error: '.$e->getMessage());
        }
        $path = $this->_joinPath($parent, $file->id);

        return $path;
    }

    /**
     * Get file contents.
     *
     * @param string $path file path
     *
     * @return string|false
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function _getContents($path)
    {
        $contents = '';

        try {
            list(, $itemId) = $this->_od_splitPath($path);
            $url = self::API_URL.$itemId.'/content';
            $contents = $this->_od_createCurl($url, $contents = true);
        } catch (Exception $e) {
            return $this->setError('OneDrive error: '.$e->getMessage());
        }

        return $contents;
    }

    /**
     * Write a string to a file.
     *
     * @param string $path    file path
     * @param string $content new file content
     *
     * @return bool
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function _filePutContents($path, $content)
    {
        $res = false;

        if ($local = $this->getTempFile($path)) {
            if (file_put_contents($local, $content, LOCK_EX) !== false
            && ($fp = fopen($local, 'rb'))) {
                clearstatcache();
                $res = $this->_save($fp, $path, '', array());
                fclose($fp);
            }
            file_exists($local) && unlink($local);
        }

        return $res;
    }

    /**
     * Detect available archivers.
     **/
    protected function _checkArchivers()
    {
        // die('Not yet implemented. (_checkArchivers)');
        return array();
    }

    /**
     * chmod implementation.
     *
     * @return bool
     **/
    protected function _chmod($path, $mode)
    {
        return false;
    }

    /**
     * Unpack archive.
     *
     * @param string $path archive path
     * @param array  $arc  archiver command and arguments (same as in $this->archivers)
     *
     * @return true
     *
     * @author Dmitry (dio) Levashov
     * @author Alexey Sukhotin
     **/
    protected function _unpack($path, $arc)
    {
        die('Not yet implemented. (_unpack)');
        //return false;
    }

    /**
     * Recursive symlinks search.
     *
     * @param string $path file/dir path
     *
     * @return bool
     *
     * @author Dmitry (dio) Levashov
     **/
    protected function _findSymlinks($path)
    {
        die('Not yet implemented. (_findSymlinks)');
    }

    /**
     * Extract files from archive.
     *
     * @param string $path archive path
     * @param array  $arc  archiver command and arguments (same as in $this->archivers)
     *
     * @return true
     *
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    protected function _extract($path, $arc)
    {
        die('Not yet implemented. (_extract)');
    }

    /**
     * Create archive and return its path.
     *
     * @param string $dir   target dir
     * @param array  $files files names list
     * @param string $name  archive name
     * @param array  $arc   archiver options
     *
     * @return string|bool
     *
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    protected function _archive($dir, $files, $name, $arc)
    {
        die('Not yet implemented. (_archive)');
    }
} // END class