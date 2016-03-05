<?php
/**
 * @package      Crowdfunding
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2015 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      GNU General Public License version 3 or later; see LICENSE.txt
 */

// no direct access
defined('_JEXEC') or die;

jimport('Prism.init');
jimport('Crowdfunding.init');
jimport('EmailTemplates.init');

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
        parent::__construct($subject, $config);

        $this->serviceProvider = 'Payeer';
        $this->serviceAlias    = 'payeer';
        $this->textPrefix .= '_' . \JString::strtoupper($this->serviceAlias);
        $this->debugType .= '_' . \JString::strtoupper($this->serviceAlias);

        $this->extraDataKeys = array(
            'm_desc', 'm_orderid', 'm_amount', 'm_curr', 'm_status', 'm_shop', 'm_sign',
            'm_operation_id', 'm_operation_ps', 'm_operation_date', 'm_operation_pay_date', 'summa_out', 'transfer_id'
        );
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
    public function onProjectPayment($context, &$item, &$params)
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
        $merchantId = JString::trim($this->params->get('merchant_id'));
        if (!$merchantId) {
            $html[] = $this->generateSystemMessage(JText::_($this->textPrefix . '_ERROR_PAYMENT_RECEIVER_MISSING'));

            return implode("\n", $html);
        }

        // Display additional information.
        $html[] = '<p>' . JText::_($this->textPrefix . '_INFO') . '</p>';

        // Generate order ID.
        $orderId = JString::strtoupper(Prism\Utilities\StringHelper::generateRandomString(16));

        // Get payment session
        $paymentSessionContext = Crowdfunding\Constants::PAYMENT_SESSION_CONTEXT . $item->id;
        $paymentSessionLocal   = $this->app->getUserState($paymentSessionContext);

        $paymentSession = $this->getPaymentSession(array(
            'session_id' => $paymentSessionLocal->session_id
        ));

        // Store order ID.
        $paymentSession->setUniqueKey($orderId);
        $paymentSession->storeUniqueKey();

        $argumentsHash = array(
            $merchantId,
            $orderId,
            number_format($item->amount, 2, '.', ''),
            $item->currencyCode,
            base64_encode(JText::sprintf($this->textPrefix . '_INVESTING_IN_S', $item->title)),
            $this->params->get('secret_key')
        );

        $sign = JString::strtoupper(hash('sha256', implode(':', $argumentsHash)));

        // Start the form.
        $html[] = '<form action="' . $this->params->get('merchant_url') . '" method="get">';
        $html[] = '<input type="hidden" name="m_shop" value="' . $argumentsHash[0] . '" />';
        $html[] = '<input type="hidden" name="m_orderid" value="' . $argumentsHash[1] . '" />';
        $html[] = '<input type="hidden" name="m_amount" value="' . $argumentsHash[2] . '" />';
        $html[] = '<input type="hidden" name="m_curr" value="' . $argumentsHash[3] . '" />';
        $html[] = '<input type="hidden" name="m_desc" value="' . $argumentsHash[4] . '" />';
        $html[] = '<input type="hidden" name="m_sign" value="' . $sign . '" />';
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
     * @return null|array
     */
    public function onPaymentNotify($context, &$params)
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
        $ipFilter = JString::trim($this->params->get('ip_filter'));
        $ipFilter = ($ipFilter !== '') ? explode(',', $ipFilter) : array();
        array_walk($ipFilter, 'JString::trim');

        $remoteAddress = $this->app->input->server->get('REMOTE_ADDR');
        if (count($ipFilter) > 0 and !in_array($remoteAddress, $ipFilter, true)) {
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_REMOTE_ADDRESS'),
                $this->debugType,
                JText::sprintf($this->textPrefix . '_REMOTE_ADDRESS_S', $remoteAddress)
            );

            return null;
        };

        // Prepare the array that have to be returned by this method.
        $result = array(
            'project'          => null,
            'reward'           => null,
            'transaction'      => null,
            'payment_session'  => null,
            'service_provider' => $this->serviceProvider,
            'service_alias'    => $this->serviceAlias,
            'response'         => $this->serviceAlias
        );

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

            $signHash = JString::strtoupper(hash('sha256', implode(':', $arHash)));
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_HASH'), $this->debugType, $signHash) : null;

        if ($_POST['m_sign'] === $signHash and $this->app->input->post->get('m_status') === 'success') {

            // Get currency
            $currency = Crowdfunding\Currency::getInstance(JFactory::getDbo(), $params->get('project_currency'));

            // Get payment session data
            $paymentSession = $this->getPaymentSession(array(
                'unique_key' => $this->app->input->post->get('m_orderid')
            ));

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PAYMENT_SESSION'), $this->debugType, $paymentSession->getProperties()) : null;

            // Validate transaction data
            $validData = $this->validateData($_POST, $currency->getCode(), $paymentSession);
            if ($validData === null) {
                return $result;
            }

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_VALID_DATA'), $this->debugType, $validData) : null;

            // Get project.
            $projectId = Joomla\Utilities\ArrayHelper::getValue($validData, 'project_id');
            $project   = Crowdfunding\Project::getInstance(JFactory::getDbo(), $projectId);

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PROJECT_OBJECT'), $this->debugType, $project->getProperties()) : null;

            // Check for valid project
            if (!$project->getId()) {

                // Log data in the database
                $this->log->add(
                    JText::_($this->textPrefix . '_ERROR_INVALID_PROJECT'),
                    $this->debugType,
                    $validData
                );

                return $result;
            }

            // Set the receiver of funds.
            $validData['receiver_id'] = $project->getUserId();

            // Save transaction data.
            // If it is not completed, return empty results.
            // If it is complete, continue with process transaction data
            $transactionData = $this->storeTransaction($validData, $project);
            if ($transactionData === null) {
                return $result;
            }

            // Update the number of distributed reward.
            $rewardId = Joomla\Utilities\ArrayHelper::getValue($transactionData, 'reward_id', 0, 'int');
            $reward   = null;
            if ($rewardId > 0) {
                $reward = $this->updateReward($transactionData);

                // Validate the reward.
                if (!$reward) {
                    $transactionData['reward_id'] = 0;
                }
            }

            // Generate object of data, based on the transaction properties.
            $result['transaction'] = Joomla\Utilities\ArrayHelper::toObject($transactionData);

            // Generate object of data based on the project properties.
            $properties        = $project->getProperties();
            $result['project'] = Joomla\Utilities\ArrayHelper::toObject($properties);

            // Generate object of data based on the reward properties.
            if ($reward !== null and ($reward instanceof Crowdfunding\Reward)) {
                $properties       = $reward->getProperties();
                $result['reward'] = Joomla\Utilities\ArrayHelper::toObject($properties);
            }

            // Generate data object, based on the payment session properties.
            $properties                = $paymentSession->getProperties();
            $result['payment_session'] = Joomla\Utilities\ArrayHelper::toObject($properties);

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RESULT_DATA'), $this->debugType, $result) : null;

            // Remove payment session.
            $txnStatus       = (isset($result['transaction']->txn_status)) ? $result['transaction']->txn_status : null;
            $removeIntention = (strcmp('completed', $txnStatus) === 0);

            $this->closePaymentSession($paymentSession, $removeIntention);

            // Store project ID in user session.
            $this->app->setUserState('payments.pid', $result['project']->id);

        } else {

            // Log error
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_DATA'),
                $this->debugType,
                array('_POST' => $_POST)
            );

            $result['response'] = $this->app->input->post->get('m_orderid') . '|error';
        }

        $result['response'] = $this->app->input->post->get('m_orderid') . '|success';

        return $result;
    }

    /**
     * Complete checkout.
     *
     * @param string                   $context
     * @param stdClass                 $item
     * @param Joomla\Registry\Registry $params
     *
     * @return array|null
     */
    public function onPaymentsCompleteCheckout($context, &$item, &$params)
    {
        JDEBUG ? $this->log->add('context', $this->debugType, $context) : null;

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

        // Check the status of checkout.
        $status = $this->app->input->getCmd('status');
        if (strcmp('success', $status) === 0) {
            $redirectUrl = JRoute::_(CrowdfundingHelperRoute::getBackingRoute($item->slug, $item->gcatslug, 'share'));
        } else {
            $redirectUrl = JRoute::_(CrowdfundingHelperRoute::getBackingRoute($item->slug, $item->gcatslug));
        }

        return array(
            'redirect_url' => $redirectUrl
        );
    }

    /**
     * Validate PayPal transaction.
     *
     * @param array                        $data
     * @param string                       $currencyCode
     * @param Crowdfunding\Payment\Session $paymentSession
     *
     * @return array
     */
    protected function validateData($data, $currencyCode, $paymentSession)
    {
        $txnDate = Joomla\Utilities\ArrayHelper::getValue($data, 'payment_date');
        $date    = new JDate($txnDate);

        $txnStatus = JString::strtolower(Joomla\Utilities\ArrayHelper::getValue($data, 'm_status', null, 'string'));
        $txnStatus = ($txnStatus === 'success') ? 'completed' : 'fail';

        // Prepare transaction data
        $transaction = array(
            'investor_id'      => (int)$paymentSession->getUserId(),
            'project_id'       => (int)$paymentSession->getProjectId(),
            'reward_id'        => ($paymentSession->isAnonymous()) ? 0 : (int)$paymentSession->getRewardId(),
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

            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_DATA'),
                $this->debugType,
                $transaction
            );

            return null;
        }

        // Check currency
        if (strcmp($transaction['txn_currency'], $currencyCode) !== 0) {

            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_CURRENCY'),
                $this->debugType,
                array('TRANSACTION DATA' => $transaction, 'CURRENCY' => $currencyCode)
            );

            return null;
        }

        // Check payment receiver.
        $paymentReceiver = Joomla\Utilities\ArrayHelper::getValue($data, 'm_shop', '', 'string');
        if ($paymentReceiver !== $this->params->get('merchant_id')) {
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_RECEIVER'),
                $this->debugType,
                array('TRANSACTION DATA' => $transaction, 'VALID RECEIVER' => $this->params->get('merchant_id'), 'INVALID RECEIVER' => $paymentReceiver,)
            );

            return null;
        }

        return $transaction;
    }

    /**
     * Save transaction data.
     *
     * @param array                $transactionData
     * @param Crowdfunding\Project $project
     *
     * @return null|array
     */
    protected function storeTransaction($transactionData, $project)
    {
        // Get transaction by txn ID
        $keys        = array(
            'txn_id' => Joomla\Utilities\ArrayHelper::getValue($transactionData, 'txn_id')
        );
        $transaction = new Crowdfunding\Transaction(JFactory::getDbo());
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

        // Store the new transaction data.
        $transaction->bind($transactionData);
        $transaction->store();

        // If it is not completed (it might be pending or other status),
        // stop the process. Only completed transaction will continue
        // and will process the project, rewards,...
        if (!$transaction->isCompleted()) {
            return null;
        }

        // Set transaction ID.
        $transactionData['id'] = $transaction->getId();

        // If the new transaction is completed,
        // update project funded amount.
        $amount = Joomla\Utilities\ArrayHelper::getValue($transactionData, 'txn_amount');
        $project->addFunds($amount);
        $project->storeFunds();

        return $transactionData;
    }
}
