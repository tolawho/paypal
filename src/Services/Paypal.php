<?php

namespace Tolawho\Paypal\Services;

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
use PayPal\Rest\ApiContext;

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
     * @param string $transactionDescription Description for transaction
     * @return bool|string
     * @throws Exception
     */
    public function createPayment($transactionDescription)
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
            ->setDescription($transactionDescription);

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
            ->setTransactions(array($transaction));

        $request = clone $payment;

        // Do create payment
        try {
            $payment->create($this->apiContext);
        } catch (\PayPal\Exception\PayPalConnectionException $e) {
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
        } catch (\PayPal\Exception\PayPalConnectionException $e) {
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
        } catch (\PayPal\Exception\PayPalConnectionException $e) {
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
        $plan->setName('App Name Monthly Billing')
            ->setDescription('Monthly Subscription to the App Name')
            ->setType('infinite');

        // Set billing plan definitions
        $paymentDefinition = new PaymentDefinition();
        $paymentDefinition->setName('Regular Payments')
            ->setType('REGULAR')
            ->setFrequency('Month')
            ->setFrequencyInterval('1')
            ->setCycles('0')
            ->setAmount(new Currency([
                'value' => 9.0,
                'currency' => $this->getCurrency()
            ]));

        // Set merchant preferences
        $merchantPreferences = new MerchantPreferences();
        $merchantPreferences->setReturnUrl($this->getReturnUrl())
            ->setCancelUrl($this->getCancelUrl())
            ->setAutoBillAmount('yes')
            ->setInitialFailAmountAction('CONTINUE')
            ->setMaxFailAttempts('0');

        $plan->setPaymentDefinitions([$paymentDefinition]);
        $plan->setMerchantPreferences($merchantPreferences);

        // Create the plan
        try {
            $createdPlan = $plan->create($this->apiContext);
            try {
                $patch = new Patch();
                $value = new PayPalModel('{"state":"ACTIVE"}');
                $patch->setOp('replace')
                    ->setPath('/')
                    ->setValue($value);
                $patchRequest = new PatchRequest();
                $patchRequest->addPatch($patch);
                $createdPlan->update($patchRequest, $this->apiContext);
                return Plan::get($createdPlan->getId(), $this->apiContext);
            } catch (PayPal\Exception\PayPalConnectionException $ex) {
                echo $ex->getCode();
                echo $ex->getData();
                die($ex);
            } catch (Exception $ex) {
                die($ex);
            }
        } catch (PayPal\Exception\PayPalConnectionException $ex) {
            echo $ex->getCode();
            echo $ex->getData();
            die($ex);
        } catch (Exception $ex) {
            die($ex);
        }
    }


}