<?php namespace Arcanedev\NoCaptcha;

use Arcanedev\NoCaptcha\Contracts\NoCaptchaInterface;
use Arcanedev\NoCaptcha\Contracts\Utilities\AttributesInterface;
use Arcanedev\NoCaptcha\Contracts\Utilities\RequestInterface;
use Arcanedev\NoCaptcha\Exceptions\ApiException;
use Arcanedev\NoCaptcha\Exceptions\InvalidTypeException;
use Arcanedev\NoCaptcha\Utilities\Attributes;
use Arcanedev\NoCaptcha\Utilities\Request;

class NoCaptcha implements NoCaptchaInterface
{
    /* ------------------------------------------------------------------------------------------------
     |  Constants
     | ------------------------------------------------------------------------------------------------
     */
    const CLIENT_URL = 'https://www.google.com/recaptcha/api.js';
    const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    /* ------------------------------------------------------------------------------------------------
     |  Properties
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * The shared key between your site and ReCAPTCHA
     *
     * @var string
     */
    private $secret;

    /**
     * Your site key
     *
     * @var string
     */
    private $siteKey;

    /**
     * Forces the widget to render in a specific language.
     * Auto-detects the user's language if unspecified.
     *
     * @var string
     */
    protected $lang;

    /**
     * Decides if we've already loaded the script file or not.
     *
     * @param bool
     */
    protected $scriptLoaded = false;

    /**
     * HTTP Request Client
     *
     * @var RequestInterface
     */
    protected $request;

    /**
     * noCaptcha Attributes
     *
     * @var AttributesInterface
     */
    protected $attributes;

    /* ------------------------------------------------------------------------------------------------
     |  Constructor
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Constructor
     *
     * @param string      $secret
     * @param string      $siteKey
     * @param string|null $lang
     */
    public function __construct($secret, $siteKey, $lang = null)
    {
        $this->setSecret($secret);
        $this->setSiteKey($siteKey);
        $this->setLang($lang);

        $this->setRequestClient(new Request);
        $this->setAttributes(new Attributes);
    }

    /* ------------------------------------------------------------------------------------------------
     |  Getters & Setters
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Set the secret key
     *
     * @param  string $secret
     *
     * @return NoCaptcha
     */
    protected function setSecret($secret)
    {
        $this->checkKey('secret key', $secret);

        $this->secret = $secret;

        return $this;
    }

    /**
     * Set Site key
     *
     * @param  string $siteKey
     *
     * @return NoCaptcha
     */
    protected function setSiteKey($siteKey)
    {
        $this->checkKey('site key', $siteKey);

        $this->siteKey = $siteKey;

        return $this;
    }

    /**
     * Set language code
     * @todo: Adding locale check OR not ?
     *
     * @param  string $lang
     *
     * @return NoCaptcha
     */
    protected function setLang($lang)
    {
        $this->lang = $lang;

        return $this;
    }

    /**
     * Get script source link
     *
     * @param  string|null $callbackName
     *
     * @return string
     */
    private function getScriptSrc($callbackName = null)
    {
        $queries = [];

        if ( ! empty($this->lang)) {
            $queries['hl'] = $this->lang;
        }

        if ( ! is_null($callbackName) and ! empty($callbackName)) {
            $queries['onload'] = $callbackName;
            $queries['render'] = 'explicit';
        }

        $queries = count($queries) ? '?' . http_build_query($queries) : '';

        return static::CLIENT_URL . $queries;
    }

    /**
     * Set HTTP Request Client
     *
     * @param  RequestInterface $request
     *
     * @return NoCaptcha
     */
    public function setRequestClient(RequestInterface $request)
    {
        $this->request = $request;

        return $this;
    }

    /**
     * Set noCaptcha Attributes
     *
     * @param  AttributesInterface $attributes
     *
     * @return NoCaptcha
     */
    public function setAttributes(AttributesInterface $attributes)
    {
        $this->attributes = $attributes;

        return $this;
    }

    /* ------------------------------------------------------------------------------------------------
     |  Main Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Display Captcha
     *
     * @param  array $attributes
     *
     * @return string
     */
    public function display($attributes = [])
    {
        $output = $this->attributes->build($this->siteKey, $attributes);

        return '<div ' . $output . '></div>';
    }

    /**
     * Display image Captcha
     *
     * @param  array $attributes
     *
     * @return string
     */
    public function image($attributes = [])
    {
        return $this->display(array_merge(
            $attributes,
            $this->attributes->getImageAttribute()
        ));
    }

    /**
     * Display audio Captcha
     *
     * @param  array $attributes
     *
     * @return string
     */
    public function audio($attributes = [])
    {
        return $this->display(array_merge(
            $attributes,
            $this->attributes->getAudioAttribute()
        ));
    }

    /**
     * Verify Response
     *
     * @param  string $response
     * @param  string $clientIp
     *
     * @return bool
     */
    public function verify($response, $clientIp = null)
    {
        if (empty($response)) {
            return false;
        }

        $response = $this->sendVerifyRequest([
            'secret'   => $this->secret,
            'response' => $response,
            'remoteip' => $clientIp
        ]);

        return isset($response['success']) and
               $response['success'] === true;
    }

    /**
     * Get script tag
     *
     * @param  string|null $callbackName
     *
     * @return string
     */
    public function script($callbackName = null)
    {
        $script = '';

        if ( ! $this->scriptLoaded) {
            $script = '<script src="' . $this->getScriptSrc($callbackName) . '" async defer></script>';
            $this->scriptLoaded = true;
        }

        return $script;
    }

    /**
     * Get script tag with a callback function
     *
     * @param  array  $captchas
     * @param  string $callbackName
     *
     * @return string
     */
    public function scriptWithCallback(array $captchas, $callbackName = 'captchaRenderCallback')
    {
        $script = $this->script($callbackName);

        if (empty($script) or empty($captchas)) {
            return $script;
        }

        return implode(PHP_EOL, [$script, implode(PHP_EOL, [
            '<script>',
                "var $callbackName = function() {",
                    $this->renderCaptchas($captchas),
                '};',
            '</script>'
        ])]);
    }

    /**
     * Rendering captchas with callback function
     *
     * @param  array $captchas
     *
     * @return string
     */
    private function renderCaptchas(array $captchas)
    {
        return implode(PHP_EOL, array_map(function($captcha) {
            return "grecaptcha.render('{$captcha}', {'sitekey' : '{$this->siteKey}'});";
        }, $captchas));
    }

    /* ------------------------------------------------------------------------------------------------
     |  Check Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Check key
     *
     * @param  string $name
     * @param  string $value
     *
     * @throws ApiException
     * @throws InvalidTypeException
     */
    private function checkKey($name, &$value)
    {
        $this->checkIsString($name, $value);

        $value = trim($value);

        $this->checkIsNotEmpty($name, $value);
    }

    /**
     * Check if the value is a string value
     *
     * @param  string $name
     * @param  string $value
     *
     * @throws InvalidTypeException
     */
    private function checkIsString($name, $value)
    {
        if ( ! is_string($value)) {
            throw new InvalidTypeException(
                'The ' . $name . ' must be a string value, ' . gettype($value) . ' given'
            );
        }
    }

    /**
     * Check if the value is not empty
     *
     * @param string $name
     * @param string $value
     *
     * @throws ApiException
     */
    private function checkIsNotEmpty($name, $value)
    {
        if (empty($value)) {
            throw new ApiException('The ' . $name . ' must not be empty');
        }
    }

    /* ------------------------------------------------------------------------------------------------
     |  Other functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Send verify request to API and get response
     *
     * @param  array $query
     *
     * @return array
     */
    private function sendVerifyRequest(array $query = [])
    {
        $query    = array_filter($query);
        $url      = static::VERIFY_URL . '?' . http_build_query($query);
        $response = $this->request->send($url);

        return $response;
    }
}
