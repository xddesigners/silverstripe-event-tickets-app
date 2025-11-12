<?php

namespace XD\EventTickets\App\Controller;

use XD\EventTickets\Forms\CheckInValidator;
use XD\EventTickets\Model\CheckInValidatorResult;
use Exception;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\ORM\ValidationResult;

/**
 * TicketValidator.php
 *
 * @author Bram de Leeuw
 * Date: 14/06/2017
 */
class TicketValidator extends Controller
{
    /**
     * Handle the request
     *
     * @param HTTPRequest $request
     * @return HTTPResponse|string
     * @throws \SilverStripe\Omnipay\Exception\Exception
     */
    public function index(HTTPRequest $request)
    {
        $body = json_decode($request->getBody(), true);
        if (Authenticator::authenticate($request) && isset($body['ticket']) && $code = $body['ticket']) {
            $validator = new CheckInValidator();
            $result = $validator->validate($code);
            
            try {
                CheckInValidatorResult::createFromValidatorResult($result)->write();
            } catch (Exception $e) {
                // soft fail
            }

            if ($attendee = $validator->getAttendee()) {
                $result['attendee'] = array(
                    'name' => $attendee->getName(),
                    'ticket' => $attendee->Ticket()->Title,
                    'event' => $attendee->TicketPage()->Title,
                    'date' => date('d-m-Y H:i:s'),
                    'type' => $result['Code'],
                    'id' => uniqid()
                );
            }

            switch ($result['Code']) {
                case CheckInValidator::MESSAGE_CHECK_OUT_SUCCESS:
                    $attendee->checkOut();
                    break;
                case CheckInValidator::MESSAGE_CHECK_IN_SUCCESS:
                    $attendee->checkIn();
                    break;
                default:
                    return json_encode(array_change_key_case($result));
            }

            $response = new HTTPResponse(json_encode(array_change_key_case($result)), 200);
            $response->addHeader('Content-Type', 'application/json');
            return $response;
        } else {
            $response = new HTTPResponse(json_encode(array(
                'code' => ValidationResult::TYPE_ERROR,
                'message' => _t('TicketValidator.ERROR_TOKEN_AUTHENTICATION_FAILED', 'The request could not be authenticated, try to log in again.')
            )), 401);
            $response->addHeader('Content-Type', 'application/json');
            return $response;
        }
    }
}
