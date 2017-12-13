<?php

namespace Tolawho\Paypal\Services;

use Carbon\Carbon;
use PayPal\Api\Agreement;
use PayPal\Api\AgreementStateDescriptor;
use PayPal\Api\Amount;
use PayPal\Api\Currency;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\MerchantPreferences;
use PayPal\Api\Patch;
use PayPal\Api\PatchRequest;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\PaymentDefinition;
use PayPal\Api\PaymentExecution;
use PayPal\Api\Plan;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Transaction;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Common\PayPalModel;
use PayPal\Exception\PayPalConnectionException;
use PayPal\Rest\ApiContext;
use Exception;

class Paypal
{

    private $apiContext;

    private $clientId;

    private $clientSecret;

    private $planId;

    private $itemList;

    private $currency = 'USD';

    private $totalAmount = 0;

    private $returnUrl;

    private $cancelUrl;

    /**
     * PayPalService constructor.
     */
    public function __construct()
    {
        $this->clientId = config('paypal.client_id');
        $this->clientSecret = config('paypal.secret');
        $this->initialApiContext(); // Initial Api Context
        $this->setCurrency(config('paypal.currency'));
    }

    /**
     * Initial api context
     *
     * @return void
     */
    private function initialApiContext()
    {
        $this->apiContext = new ApiContext(
            new OAuthTokenCredential(
                $this->clientId,
                $this->clientSecret
            )
        );
        $this->apiContext->setConfig(config('paypal.settings'));
    }

    /**
     * Set payment currency
     *
     * @param string $currency String name of currency
     * @return self
     */
    public function setCurrency($currency)
    {
        $this->currency = $currency;

        return $this;
    }

    /**
     * Get current payment currency
     *
     * @return string Current payment currency
     */
    public function getCurrency()
    {
        return $this->currency;
    }

    /**
     * Add item to list
     *
     * @param array $itemData list item data
     * @return self
     */
    public function setItem($itemData)
    {
        if (count($itemData) === count($itemData, COUNT_RECURSIVE)) {
            $itemData = [$itemData];
        }

        foreach ($itemData as $data) {
            // Create new item
            $item = new Item();

            // Set item info
            $item->setName($data['name'])
                ->setCurrency($this->getCurrency())
                ->setSku($data['sku'])// id of item
                ->setQuantity($data['quantity'])
                ->setPrice($data['price']);

            // Insert into item list
            $this->itemList[] = $item;
            // Calculate total amount
            $this->totalAmount += $data['price'] * $data['quantity'];
        }

        return $this;
    }

    /**
     * Get list item
     *
     * @return array List item
     */
    public function getItemList()
    {
        return $this->itemList;
    }

    /**
     * Get total amount
     *
     * @return mixed Total amount
     */
    public function getTotalAmount()
    {
        return $this->totalAmount;
    }

    /**
     * Set return URL
     *
     * @param string $url Return URL for payment process complete
     * @return self
     */
    public function setReturnUrl($url)
    {
        $this->returnUrl = $url;

        return $this;
    }

    /**
     * Get return URL
     *
     * @return string Return URL
     */
    public function getReturnUrl()
    {
        return $this->returnUrl;
    }

    /**
     * Set cancel URL
     *
     * @param string $url Cancel URL for payment
     * @return self
     */
    public function setCancelUrl($url)
    {
        $this->cancelUrl = $url;

        return $this;
    }

    /**
     * Get cancel URL of payment
     *
     * @return string Cancel URL
     */
    public function getCancelUrl()
    {
        return $this->cancelUrl;
    }

    /**
     * Create payment
     *
     * @param array $data
     * $data = [
     *  'items' => // list item
     *  'total' => // total amount of all items
     *  'desc'  => // description for transaction
     * ]
     * @return bool|string
     * @throws Exception
     */
    public function createPayment($data = [])
    {
        // Set payment method
        $payer = new Payer();
        $payer->setPaymentMethod('paypal');

        // Create new item list
        $itemList = new ItemList();
        $itemList->setItems($this->getItemList());

        // Amount and currency
        $amount = new Amount();
        $amount->setCurrency($this->getCurrency())
            ->setTotal($this->getTotalAmount());

        // Create new Transaction object
        $transaction = new Transaction();
        $transaction->setAmount($amount)
            ->setItemList($itemList)
            ->setDescription($data['desc']);

        // URL to process a transaction successful.
        $redirectUrls = new RedirectUrls();

        //Check to see if a link exists when the user cancels the payment.
        // Otherwise, by default we will always use $ redirectUrl
        if (is_null($this->cancelUrl)) {
            $this->cancelUrl = $this->returnUrl;
        }

        $redirectUrls->setReturnUrl($this->returnUrl)
            ->setCancelUrl($this->cancelUrl);

        // Create new Payment object
        $payment = new Payment();
        $payment->setIntent("sale")
            ->setPayer($payer)
            ->setRedirectUrls($redirectUrls)
            ->setTransactions([$transaction]);

        $request = clone $payment;

        // Do create payment
        try {
            $payment->create($this->apiContext);
        } catch (PayPalConnectionException $e) {
            logger("Created Payment Using PayPal. Please visit the URL to Approve.", $request);
            throw new \Exception($e->getMessage());
        }

        // Save the payment ID to the session to check the payment in another function
        session(['paypal_payment_id' => $payment->getId()]);

        return $payment->getApprovalLink(); // Return approved link to perform the redirection
    }

    /**
     * Get payment status
     *
     * @return mixed Object payment details or false
     */
    public function getPaymentStatus()
    {
        // Get all parameter from Paypal callback url
        $request = request()->all();

        // Get payment id from session
        $paymentId = session('paypal_payment_id');

        // Clear payment id from session
        session()->forget('paypal_payment_id');

        // Check if the URL returned from PayPal contains
        // queries required of a successful payment
        // or not.
        if (empty($request['PayerID']) || empty($request['token'])) {
            return false;
        }

        // Create payment from Payment ID
        $payment = Payment::get($paymentId, $this->apiContext);

        // Get payment detail
        $paymentExecution = new PaymentExecution();
        $paymentExecution->setPayerId($request['PayerID']);

        return $payment->execute($paymentExecution, $this->apiContext);
    }

    /**
     * Get payment details by payment id
     *
     * @param $paymentId
     * @return Payment
     * @throws Exception
     */
    public function getPaymentDetails($paymentId)
    {
        try {
            return Payment::get($paymentId, $this->apiContext);
        } catch (PayPalConnectionException $e) {
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * Get payment list
     *
     * @param int $limit
     * @param int $offset
     * @return \PayPal\Api\PaymentHistory
     * @throws Exception
     */
    public function getPaymentList($limit = 10, $offset = 0)
    {
        try {
            return Payment::all([
                'count' => $limit,
                'start_index' => $offset
            ], $this->apiContext);
        } catch (PayPalConnectionException $e) {
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * Create new plan
     */
    public function createPlan()
    {
        // Create a new billing plan
        $plan = new Plan();
        $plan->setName('Trial 04')
            ->setDescription('Monthly Subscription')
            ->setType('INFINITE');

        // Set billing plan definitions
        $paymentDefinition = new PaymentDefinition();
        $paymentDefinition->setName('Regular Payments')
            ->setType('REGULAR')
            ->setFrequency('DAY')
            ->setFrequencyInterval(1)
            ->setCycles(0)
            ->setAmount(new Currency([
                'value' => 0.9,
                'currency' => $this->getCurrency()
            ]));

        $paymentDefinitionTrial = new PaymentDefinition();
        $paymentDefinitionTrial->setName('Trial Payments')
            ->setType('TRIAL')
            ->setFrequency('DAY')
            ->setFrequencyInterval(1)
            ->setCycles(1)
            ->setAmount(new Currency([
                'value' => 0.0,
                'currency' => $this->getCurrency()
            ]));

        // Set merchant preferences
        $merchantPreferences = new MerchantPreferences();
        $merchantPreferences->setReturnUrl($this->getReturnUrl())
            ->setCancelUrl($this->getCancelUrl())
            ->setAutoBillAmount('yes')
            ->setInitialFailAmountAction('CONTINUE')
            ->setMaxFailAttempts('0');

        $plan->addPaymentDefinition($paymentDefinition);
        $plan->addPaymentDefinition($paymentDefinitionTrial);

        //$plan->setPaymentDefinitions([$paymentDefinition, $paymentDefinitionTrial]);
        $plan->setMerchantPreferences($merchantPreferences);

        // Create the plan
        try {
            return $plan->create($this->apiContext);
        } catch (PayPalConnectionException $ex) {
            echo $ex->getCode();
            echo $ex->getData();
            die($ex);
        } catch (Exception $ex) {
            die($ex);
        }
    }

    /**
     * Show billing plan details
     *
     * @param $planId
     * @return Plan
     */
    public function planDetails($planId)
    {
        try {
            return Plan::get($planId, $this->apiContext);
        } catch (PayPalConnectionException $ex) {
            echo $ex->getCode();
            echo $ex->getData();
            die($ex);
        }
    }

    /**
     * Lists billing plans.
     *
     * @return \PayPal\Api\PlanList
     */
    public function listPlans()
    {
        try {
            return Plan::all([], $this->apiContext);
        } catch (PayPalConnectionException $ex) {
            echo $ex->getCode();
            echo $ex->getData();
            die($ex);
        }
    }

    /**
     * Updates fields in a billing plan, by ID
     *
     * @param $planId
     * @return \PayPal\Api\PlanList
     */
    public function activePlan($planId)
    {
        try {
            $patch = new Patch();
            $value = new PayPalModel('{
                "state":"ACTIVE"
            }');
            $patch->setOp('replace')
                ->setPath('/')
                ->setValue($value);
            $patchRequest = new PatchRequest();
            $patchRequest->addPatch($patch);
            $plan = new Plan();
            $plan->setId($planId);
            $plan->update($patchRequest, $this->apiContext);
            return Plan::get($planId, $this->apiContext);
        } catch (PayPalConnectionException $ex) {
            echo $ex->getCode();
            echo $ex->getData();
            die(1);
        }
    }

    /**
     * Delete a billing plan
     *
     * @param $planId
     * @return bool
     */
    public function deletePlan($planId)
    {
        try {
            $plan = new Plan();
            $plan->setId($planId);
            $plan->delete($this->apiContext);
            return true;
        } catch (PayPalConnectionException $ex) {
            echo $ex->getCode();
            echo $ex->getData();
            die(1);
        }
    }

    /**
     * Create Billing Agreement
     *
     * @param $planId
     * @return \Illuminate\Http\RedirectResponse
     */
    public function createBillingAgreement($planId)
    {
        $agreement = new Agreement();
        $agreement->setName('Base Agreement')
            ->setDescription('Basic Agreement')
            ->setStartDate(Carbon::now()->addMinute()->toAtomString());

        // Add Plan ID, please note that the plan Id should be only set in this case.
        $plan = new Plan();
        $plan->setId($planId);
        $agreement->setPlan($plan);

        // Add Payer
        $payer = new Payer();
        $payer->setPaymentMethod('paypal');
        $agreement->setPayer($payer);

        // ### Create Agreement
        try {
            // Please note that as the agreement has not yet activated, we wont be receiving the ID just yet.
            $agreement = $agreement->create($this->apiContext);
            // ### Get redirect url
            // The API response provides the url that you must redirect
            // the buyer to. Retrieve the url from the $agreement->getApprovalLink() method
            return redirect()->to($agreement->getApprovalLink());
        } catch (Exception $ex) {
            echo $ex->getCode();
            echo $ex->getData();
            die(1);
        }
    }

    /**
     * Do execute billing agreement
     *
     * @param $token
     * @return Agreement
     */
    public function executeBillingAgreement($token)
    {
        try {
            // Execute agreement
            $agreement = new Agreement();
            return $agreement->execute($token, $this->apiContext);
        } catch (PayPalConnectionException $ex) {
            echo $ex->getCode();
            echo $ex->getData();
            die(1);
        }
    }

    /**
     * Shows details for a billing agreement, by ID
     *
     * @param $agreementId
     * @return Agreement
     */
    public function getBillingAgreement($agreementId)
    {
        try {
            return Agreement::get($agreementId, $this->apiContext);
        } catch (PayPalConnectionException $ex) {
            echo $ex->getCode();
            echo $ex->getData();
            die(1);
        }
    }

    /**
     * Suspends a billing agreement, by ID.
     *
     * @param $agreementId
     * @return Agreement
     */
    public function suspendBillingAgreement($agreementId)
    {
        //Create an Agreement State Descriptor, explaining the reason to suspend.
        $agreementStateDescriptor = new AgreementStateDescriptor();
        $agreementStateDescriptor->setNote("Suspending the agreement");
        $agreement = $this->getBillingAgreement($agreementId);
        try {
            $agreement->suspend($agreementStateDescriptor, $this->apiContext);
            return $this->getBillingAgreement($agreementId);
        } catch (PayPalConnectionException $ex) {
            echo $ex->getCode();
            echo $ex->getData();
            die(1);
        }
    }

    /**
     * Re-activates a suspended billing agreement, by ID
     *
     * @param $agreementId
     * @return Agreement
     */
    public function reActivateBillingAgreement($agreementId)
    {
        //Create an Agreement State Descriptor, explaining the reason to suspend.
        $agreementStateDescriptor = new AgreementStateDescriptor();
        $agreementStateDescriptor->setNote("Re-Active the agreement");
        $agreement = $this->getBillingAgreement($agreementId);
        try {
            $agreement->reActivate($agreementStateDescriptor, $this->apiContext);
            return $this->getBillingAgreement($agreementId);
        } catch (PayPalConnectionException $ex) {
            echo $ex->getCode();
            echo $ex->getData();
            die(1);
        }
    }

    /**
     * Cancels a billing agreement, by ID
     *
     * @param $agreementId
     * @return Agreement
     */
    public function cancelBillingAgreement($agreementId)
    {
        $agreementStateDescriptor = new AgreementStateDescriptor();
        $agreementStateDescriptor->setNote("Cancel the agreement");
        $agreement = $this->getBillingAgreement($agreementId);
        try {
            $agreement->cancel($agreementStateDescriptor, $this->apiContext);
            return $this->getBillingAgreement($agreementId);
        } catch (PayPalConnectionException $ex) {
            echo $ex->getCode();
            echo $ex->getData();
            die(1);
        }
    }

    /**
     * Lists transactions for an agreement, by ID
     *
     * @param $agreementId
     * @param string $start date start to search
     * @param string $end date end to search
     * @return \PayPal\Api\AgreementTransactions
     */
    public function listTransactionBillingAgreement($agreementId, $start = null, $end = null)
    {
        // Adding Params to search transaction within a given time frame.
        $params = array('start_date' => date('Y-m-d', strtotime('-15 years')), 'end_date' => date('Y-m-d', strtotime('+5 days')));
        try {
            return Agreement::searchTransactions($agreementId, $params, $this->apiContext);
        } catch (PayPalConnectionException $ex) {
            echo $ex->getCode();
            echo $ex->getData();
            die(1);
        }
    }

    


}