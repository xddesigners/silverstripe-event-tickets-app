<?php

namespace XD\EventTickets\App\Controller;

use XD\EventTickets\App\Model\Device;
use XD\EventTickets\Forms\CheckInValidator;
use Exception;
use Firebase\JWT\JWT;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Environment;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Security\Member;
use SilverStripe\Security\MemberAuthenticator\MemberAuthenticator;
use SilverStripe\Security\Permission;
use SilverStripe\SiteConfig\SiteConfig;
use UnexpectedValueException;

/**
 * TicketValidator.php
 *
 * @author Bram de Leeuw
 * Date: 14/06/2017
 */
class Authenticator extends Controller
{
    const TYPE_ACCOUNT = 'ACCOUNT';
    const VALIDATE_TICKET = 'eventtickets/validate';
    const VALIDATE_TOKEN = 'eventtickets/authenticator/validatetoken';

    private static $icon = 'favicon-152.png';

    private static $validate_path = '';

    private static $token_header = 'X-Authorization';

    private static $jwt_alg = 'HS256';//'HS512';

    private static $jwt_nbf_offset = 0;

    private static $jwt_exp_offset = 9000;

    private static $allowed_actions = array(
        'authenticator',
        'validateToken'
    );

    /**
     * Handle the request
     * @param HTTPRequest $request
     * @return HTTPResponse
     * @throws Exception
     * @throws \ValidationException
     */
    public function index(HTTPRequest $request)
    {
        $body = json_decode($request->getBody(), true);

        if (
            isset($body['username']) &&
            isset($body['password']) &&
            isset($body['uniqueId'])
        ) {
            /** @var \Authenticator $authClass */
            $auth = new MemberAuthenticator();
            $member = $auth->authenticate(array(
                'Email' => $body['username'],
                'Password' => $body['password'],
            ), $request);

            if ($member && $member->exists()) {
                if (!Permission::check('HANDLE_CHECK_IN', 'any', $member)) {
                    return new HTTPResponse(json_encode(array(
                        'message' => _t('TicketValidator.ERROR_USER_PERMISSIONS', 'You don’t have enough permissions to handle the check in.')
                    )), 401);
                }

                $brand = isset($body['brand']) ? $body['brand'] : '-';
                $model = isset($body['model']) ? $body['model'] : '-';
                // find or create device and save token in it
                $device = Device::findOrMake($body['uniqueId'], $brand, $model);                
                $token = self::createTokenFor($member, $device);
                $device->Token = $member->encryptWithUserSettings($token);
                $member->ScanDevices()->add($device);

                return new HTTPResponse(json_encode(self::createResponseData(
                    $member,
                    $device,
                    $token
                )), 200);
            }
        }

        return new HTTPResponse(json_encode(array(
            'message' => _t('TicketValidator.ERROR_WRONG_AUTHENTICATION', 'Wrong username or password given.')
        )), 401);
    }

    /**
     * Authenticate by given JWT
     *
     * @param HTTPRequest $request
     * @return bool|SS_HTTPResponse
     * @throws Exception
     */
    public static function authenticate(HTTPRequest $request)
    {
        if ($header = $request->getHeader(self::config()->get('token_header'))) {
            list($jwt) = sscanf($header, 'Bearer %s');
            if (!empty($jwt)) {
                try {
                    $decoded = JWT::decode($jwt, self::jwtSecretKey());
                } catch (UnexpectedValueException $e) {
                    return new HTTPResponse(json_encode(array(
                        'code' => ValidationResult::TYPE_ERROR,
                        'message' => $e->getMessage()
                    )), 401);
                }

                if (
                    ($device = DataObject::get_by_id(Device::class, $decoded->data->deviceId)) &&
                    ($member = DataObject::get_by_id(Member::class, $decoded->data->memberId))
                ) {
                    return $device->Token === $member->encryptWithUserSettings($jwt);
                }
            }
        };

        return false;
    }

    /**
     * @todo refresh token when valid
     *
     * @param HTTPRequest $request
     * @return bool|HTTPRequest
     * @throws Exception
     */
    public function validateToken(HTTPRequest $request) {
        return self::authenticate($request);
    }

    public static function createTokenFor(Member $member, Device $device)
    {
        return JWT::encode([
            'iat' => $issuedAt = time(),
            'jti' => $device->ID,
            'iss' => Director::absoluteBaseURL(),
            'nbf' => $notBefore = $issuedAt + self::config()->get('jwt_nbf_offset'),
            'exp' => $notBefore + self::config()->get('jwt_exp_offset'),
            'data' => [
                'memberId' => $member->ID,
                'deviceId' => $device->ID
            ]
        ], self::jwtSecretKey(), self::config()->get('jwt_alg'));
    }

    public static function createResponseData(Member $member, Device $device, string $token)
    {
        $siteConfig = SiteConfig::current_site_config();
        return [
            'id' => $device->ID,
            'name' => $member->getName(),
            'type' => self::TYPE_ACCOUNT,
            'title' => $siteConfig->Title,
            'image' => Director::absoluteBaseURL() . self::config()->get('icon'),
            'token' => $token,
            'validatePath' => Controller::join_links(Director::absoluteBaseURL(), self::VALIDATE_TICKET),
            'validateTokenPath' => Controller::join_links(Director::absoluteBaseURL(), self::VALIDATE_TOKEN)
        ];
    }

    /**
     * Get the token or return an error response
     * @return mixed
     * @throws Exception
     */
    private static function jwtSecretKey()
    {
        $key = Environment::getEnv('JWT_SECRET_KEY');
        if (!$key) {
            throw new Exception(_t('TicketValidator.ERROR_SERVER_SETUP', 'The server is not set up properly, contact your site administrator.'));
        }

        return $key;
    }
}
