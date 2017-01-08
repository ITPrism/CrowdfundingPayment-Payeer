<?php
/**
 * @package      Crowdfunding
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2016 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      GNU General Public License version 3 or later; see LICENSE.txt
 */

use Crowdfunding\Transaction\Transaction;
use Crowdfunding\Transaction\TransactionManager;
use Crowdfunding\Reward;
use Joomla\String\StringHelper;
use Joomla\Utilities\ArrayHelper;

// no direct access
defined('_JEXEC') or die;

jimport('Prism.init');
jimport('Crowdfunding.init');
jimport('Emailtemplates.init');

JObserverMapper::addObserverClassToClass(
    'Crowdfunding\\Observer\\Transaction\\TransactionObserver',
    'Crowdfunding\\Transaction\\TransactionManager',
    array('typeAlias' => 'com_crowdfunding.payment')
);

/**
 * Crowdfunding Payeer payment plugin.
 *
 * @package      Crowdfunding
 * @subpackage   Plugins
 */
class plgCrowdfundingPaymentPayeer extends Crowdfunding\Payment\Plugin
{
    public function __construct(&$subject, $config = array())
    {
        $this->serviceProvider = 'Payeer';
        $this->serviceAlias    = 'payeer';

        $this->extraDataKeys = array(
            'm_desc', 'm_orderid', 'm_amount', 'm_curr', 'm_status', 'm_shop', 'm_sign', 'payment_date',
            'm_operation_id', 'm_operation_ps', 'm_operation_date', 'm_operation_pay_date', 'summa_out', 'transfer_id'
        );

        parent::__construct($subject, $config);
    }

    /**
     * This method prepares a payment gateway - buttons, forms,...
     * That gateway will be displayed on the summary page as a payment option.
     *
     * @param string                   $context This string gives information about that where it has been executed the trigger.
     * @param stdClass                 $item    A project data.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @return string
     */
    public function onProjectPayment($context, $item, $params)
    {
        if (strcmp('com_crowdfunding.payment', $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        // This is a URI path to the plugin folder
        $pluginURI = 'plugins/crowdfundingpayment/payeer';

        $html   = array();
        $html[] = '<div class="well">';

        $html[] = '<h4><img src="' . $pluginURI . '/images/payeer_icon.png" width="32" height="32" alt="Payeer" />' . JText::_($this->textPrefix . '_TITLE') . '</h4>';

        // Prepare payment receiver.
        $merchantId = StringHelper::trim($this->params->get('merchant_id'));
        if (!$merchantId) {
            $html[] = $this->generateSystemMessage(JText::_($this->textPrefix . '_ERROR_PAYMENT_RECEIVER_MISSING'));

            return implode("\n", $html);
        }

        // Display additional information.
        $html[] = '<p>' . JText::_($this->textPrefix . '_INFO') . '</p>';

        // Generate order ID.
        $orderId = StringHelper::strtoupper(Prism\Utilities\StringHelper::generateRandomString(16));

        // Get payment session
        $paymentSessionContext = Crowdfunding\Constants::PAYMENT_SESSION_CONTEXT . $item->id;
        $paymentSessionLocal   = $this->app->getUserState($paymentSessionContext);

        $paymentSession = $this->getPaymentSession(array(
            'session_id' => $paymentSessionLocal->session_id
        ));

        // Store order ID.
        $paymentSession->setUniqueKey($orderId);
        $paymentSession->storeUniqueKey();

        // Store project ID in user session because I will need it in next step.
        $this->app->setUserState('payments.pid', $item->id);

        $argumentsHash = array(
            $merchantId,
            $orderId,
            number_format($item->amount, 2, '.', ''),
            $item->currencyCode,
            base64_encode(JText::sprintf($this->textPrefix . '_INVESTING_IN_S', $item->title)),
            $this->params->get('secret_key')
        );

        $sign = StringHelper::strtoupper(hash('sha256', implode(':', $argumentsHash)));

        // Start the form.
        $html[] = '<form action="' . $this->params->get('merchant_url') . '" method="get">';
        $html[] = '<input type="hidden" name="m_shop" value="' .$argumentsHash[0]. '" />';
        $html[] = '<input type="hidden" name="m_orderid" value="' .$argumentsHash[1]. '" />';
        $html[] = '<input type="hidden" name="m_amount" value="' .$argumentsHash[2]. '" />';
        $html[] = '<input type="hidden" name="m_curr" value="' .$argumentsHash[3]. '" />';
        $html[] = '<input type="hidden" name="m_desc" value="' .$argumentsHash[4]. '" />';
        $html[] = '<input type="hidden" name="m_sign" value="' .$sign. '" />';
        $html[] = '<input type="submit" name="m_process" value="' . JText::_($this->textPrefix . '_PAY_NOW') . '" class="btn btn-primary" />';
        $html[] = '</form>';

        $html[] = '</div>';

        return implode("\n", $html);
    }

    /**
     * This method processes transaction data that comes from PayPal instant notifier.
     *
     * @param string                   $context This string gives information about that where it has been executed the trigger.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @throws \InvalidArgumentException
     * @throws \OutOfBoundsException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     *
     * @return null|stdClass
     */
    public function onPaymentNotify($context, $params)
    {
        if (strcmp('com_crowdfunding.notify.' . $this->serviceAlias, $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('raw', $docType) !== 0) {
            return null;
        }

        // Validate request method
        $requestMethod = $this->app->input->getMethod();
        if (strcmp('POST', $requestMethod) !== 0) {
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_REQUEST_METHOD'),
                $this->debugType,
                JText::sprintf($this->textPrefix . '_ERROR_INVALID_TRANSACTION_REQUEST_METHOD', $requestMethod)
            );

            return null;
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RESPONSE'), $this->debugType, $_POST) : null;

        // Check for valid remote address.
        $ipFilter = StringHelper::trim($this->params->get('ip_filter'));
        $ipFilter = ($ipFilter !== '') ? explode(',', $ipFilter) : array();
        array_walk($ipFilter, 'StringHelper::trim');

        $remoteAddress = $this->app->input->server->get('REMOTE_ADDR');
        if (count($ipFilter) > 0 and !in_array($remoteAddress, $ipFilter, true)) {
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_REMOTE_ADDRESS'),
                $this->debugType,
                JText::sprintf($this->textPrefix . '_REMOTE_ADDRESS_S', $remoteAddress)
            );

            return null;
        }

        // Prepare the array that have to be returned by this method.
        $paymentResult = new stdClass;
        $paymentResult->project         = null;
        $paymentResult->reward          = null;
        $paymentResult->transaction     = null;
        $paymentResult->paymentSession  = null;
        $paymentResult->serviceProvider = $this->serviceProvider;
        $paymentResult->serviceAlias    = $this->serviceAlias;
        $paymentResult->response        = null;


        $signHash = '';
        if (array_key_exists('m_operation_id', $_POST) and array_key_exists('m_sign', $_POST)) {
            $arHash = array(
                $_POST['m_operation_id'],
                $_POST['m_operation_ps'],
                $_POST['m_operation_date'],
                $_POST['m_operation_pay_date'],
                $_POST['m_shop'],
                $_POST['m_orderid'],
                $_POST['m_amount'],
                $_POST['m_curr'],
                $_POST['m_desc'],
                $_POST['m_status'],
                $this->params->get('secret_key')
            );

            $signHash = StringHelper::strtoupper(hash('sha256', implode(':', $arHash)));
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_HASH'), $this->debugType, $signHash) : null;

        if (($_POST['m_sign'] === $signHash) and ($this->app->input->post->get('m_status') === 'success')) {
            $containerHelper  = new Crowdfunding\Container\Helper();
            $currency         = $containerHelper->fetchCurrency($this->container, $params);

            // Get payment session data
            $paymentSessionRemote = $this->getPaymentSession(array(
                'unique_key' => $this->app->input->post->get('m_orderid')
            ));

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PAYMENT_SESSION'), $this->debugType, $paymentSessionRemote->getProperties()) : null;

            // Prepare valid transaction data.
            $options = array(
                'currency_code' => $currency->getCode(),
                'timezone'      => $this->app->get('offset'),
            );

            $validData = $this->validateData($_POST, $paymentSessionRemote, $options);
            if ($validData === null) {
                return $paymentResult;
            }

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_VALID_DATA'), $this->debugType, $validData) : null;

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_VALID_DATA'), $this->debugType, $validData) : null;

            // Set the receiver ID.
            $project = $containerHelper->fetchProject($this->container, $validData['project_id']);
            $validData['receiver_id'] = $project->getUserId();

            // Get reward object.
            $reward = null;
            if ($validData['reward_id']) {
                $reward = $containerHelper->fetchReward($this->container, $validData['reward_id'], $project->getId());
            }

            // Save transaction data.
            // If it is not completed, return empty results.
            // If it is complete, continue with process transaction data
            $transaction = $this->storeTransaction($validData);
            if ($transaction === null) {
                return null;
            }

            // Generate object of data, based on the transaction properties.
            $paymentResult->transaction = $transaction;

            // Generate object of data based on the project properties.
            $paymentResult->project = $project;

            // Generate object of data based on the reward properties.
            if ($reward !== null and ($reward instanceof Crowdfunding\Reward)) {
                $paymentResult->reward = $reward;
            }

            // Generate data object, based on the payment session properties.
            $paymentResult->paymentSession = $paymentSessionRemote;

            // Removing intention.
            $this->removeIntention($paymentSessionRemote, $transaction);

            // Store project ID in user session because I will need it in next step.
            $this->app->setUserState('payments.pid', $paymentResult->project->getId());
        } else {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_DATA'), $this->debugType, array('_POST' => $_POST));

            $paymentResult->response = $this->app->input->post->get('m_orderid') . '|error';
        }

        $paymentResult->response = $this->app->input->post->get('m_orderid') . '|success';

        return $paymentResult;
    }

    /**
     * Complete checkout.
     *
     * @param string                   $context
     * @param stdClass                 $item
     * @param Joomla\Registry\Registry $params
     *
     * @return null|stdClass
     */
    public function onPaymentsCompleteCheckout($context, &$item, &$params)
    {
        if (strcmp('com_crowdfunding.payments.completecheckout.' . $this->serviceAlias, $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        $paymentResult = new stdClass;

        // Check the status of checkout.
        $status = $this->app->input->getCmd('status');
        if (strcmp('success', $status) === 0) {
            $paymentResult->redirectUrl = JRoute::_(CrowdfundingHelperRoute::getBackingRoute($item->slug, $item->catslug, 'share'));
        } else {
            $paymentResult->redirectUrl = JRoute::_(CrowdfundingHelperRoute::getBackingRoute($item->slug, $item->catslug));
        }

        return $paymentResult;
    }

    /**
     * Validate PayPal transaction.
     *
     * @param array                        $data
     * @param Crowdfunding\Payment\Session $paymentSession
     * @param array                        $options
     *
     * @throws \InvalidArgumentException
     * @return array
     */
    protected function validateData($data, $paymentSession, $options)
    {
        $date      = new JDate('now', $options['timezone']);

        $txnStatus = StringHelper::strtolower(ArrayHelper::getValue($data, 'm_status', '', 'string'));
        $txnStatus = ($txnStatus === 'success') ? 'completed' : 'failed';

        // Prepare transaction data
        $transaction = array(
            'investor_id'      => (int)$paymentSession->getUserId(),
            'project_id'       => (int)$paymentSession->getProjectId(),
            'reward_id'        => $paymentSession->isAnonymous() ? 0 : (int)$paymentSession->getRewardId(),
            'service_provider' => $this->serviceProvider,
            'service_alias'    => $this->serviceAlias,
            'txn_id'           => $data['m_orderid'],
            'txn_amount'       => $data['m_amount'],
            'txn_currency'     => $data['m_curr'],
            'txn_status'       => $txnStatus,
            'txn_date'         => $date->toSql(),
            'extra_data'       => $this->prepareExtraData($data)
        );

        // Check Project ID and Transaction ID
        if (!$transaction['project_id'] or !$transaction['txn_id']) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_DATA'), $this->debugType, $transaction);
            return null;
        }

        // Check currency
        if (strcmp($transaction['txn_currency'], $options['currency_code']) !== 0) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_CURRENCY'), $this->debugType, array('TRANSACTION DATA' => $transaction, 'CURRENCY' => $options['currency_code']));
            return null;
        }

        // Check payment receiver.
        $paymentReceiver = ArrayHelper::getValue($data, 'm_shop', '', 'string');
        if ($paymentReceiver !== $this->params->get('merchant_id')) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_RECEIVER'), $this->debugType, array('TRANSACTION DATA' => $transaction, 'VALID RECEIVER' => $this->params->get('merchant_id'), 'INVALID RECEIVER' => $paymentReceiver,));

            return null;
        }

        return $transaction;
    }

    /**
     * Save transaction data.
     *
     * @param array  $transactionData
     *
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     *
     * @return Transaction|null
     */
    protected function storeTransaction($transactionData)
    {
        // Get transaction object by transaction ID
        $keys  = array(
            'txn_id' => ArrayHelper::getValue($transactionData, 'txn_id')
        );
        $transaction = new Transaction(JFactory::getDbo());
        $transaction->load($keys);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_TRANSACTION_OBJECT'), $this->debugType, $transaction->getProperties()) : null;

        // Check for existed transaction
        // If the current status if completed, stop the payment process.
        if ($transaction->getId() and $transaction->isCompleted()) {
            return null;
        }

        // Add extra data.
        if (array_key_exists('extra_data', $transactionData)) {
            if (!empty($transactionData['extra_data'])) {
                $transaction->addExtraData($transactionData['extra_data']);
            }

            unset($transactionData['extra_data']);
        }

        // IMPORTANT: It must be placed before ->bind();
        $options = array(
            'old_status' => $transaction->getStatus(),
            'new_status' => $transactionData['txn_status']
        );

        // Create the new transaction record if there is not record.
        // If there is new record, store new data with new status.
        // Example: It has been 'pending' and now is 'completed'.
        // Example2: It has been 'pending' and now is 'failed'.
        $transaction->bind($transactionData);

        // Start database transaction.
        $db = JFactory::getDbo();
        $db->transactionStart();

        try {
            $transactionManager = new TransactionManager($db);
            $transactionManager->setTransaction($transaction);
            $transactionManager->process('com_crowdfunding.payment', $options);
        } catch (Exception $e) {
            $db->transactionRollback();

            $this->log->add(JText::_($this->textPrefix . '_ERROR_TRANSACTION_PROCESS'), $this->errorType, $e->getMessage());
            return null;
        }

        // Commit database transaction.
        $db->transactionCommit();

        return $transaction;
    }
}
