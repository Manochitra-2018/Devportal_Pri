<?php

namespace Apigee\Mint;

use Apigee\ManagementAPI\Developer as ManagementDeveloper;
use Apigee\Mint\DataStructures\Payment;
use Apigee\Mint\DataStructures\RevenueReport;
use Apigee\Mint\Exceptions\MintApiException;
use Apigee\Mint\Types\BillingType;
use Apigee\Mint\Types\DeveloperType;
use Apigee\Mint\Types\DeveloperStatusType;
use Apigee\Exceptions\ParameterException;
use Apigee\Exceptions\ResponseException;
use Apigee\Util\CacheFactory;
use Apigee\Util\OrgConfig;

class Developer extends Base\BaseObject
{

    /**
     * @var double
     */
    private $mintApproxTaxRate;

    /**
     * @var array
     */
    private $addresses;

    /**
     * @var \Apigee\Mint\BankDetail
     */
    private $bankDetail;

    /**
     * @var string
     */
    private $mintBillingType;

    /**
     * @var string
     */
    private $billingProfile;

    /**
     * @var bool
     */
    private $mintIsBroker;

    /**
     * @var \Apigee\Mint\DeveloperCategory
     */
    private $mintDeveloperCategory;

    private $developerRole;

    /**
     * @var string
     * Unique identifier
     */
    public $email;

    /**
     * @var boolean
     */
    private $mintHasSelfBilling;

    /**
     * @var string
     */
    private $id;

    /**
     * @var string
     */
    private $mintDeveloperLegalName;

    /**
     * @var string
     */
    private $name;

    /**
     * @var string
     */
    private $mintRegistrationId;

    /**
     * @var \Apigee\Mint\Organization
     */
    private $organization;

    /**
     * @var \Apigee\Mint\Developer
     */
    private $parentId;

    /**
     * @var string
     */
    private $mintDeveloperPhone;

    /**
     * @var \Apigee\Mint\DeveloperRatePlan
     */
    private $ratePlan;

    /**
     * @var string
     */
    private $status;

    /**
     * @var string
     */
    private $mintTaxExemptAuthNo;

    /**
     * @var string
     */
    private $mintDeveloperType;

    /**
     * @var string[]
     */
    private $customAttributes;

    public function __construct(OrgConfig $config)
    {
        $base_url = '/mint/organizations/' . rawurlencode($config->orgName) . '/developers';
        $this->init($config, $base_url);

        $this->wrapperTag = 'developer';
        $this->idField = 'email';
        $this->idIsAutogenerated = false;

        $this->initValues();
    }

    /**
     * {@inheritdoc}
     */
    public function instantiateNew()
    {
        return new Developer($this->config);
    }

    public function loadFromRawData($data, $reset = false)
    {
        if ($reset) {
            $this->initValues();
        }
        $excluded_properties = array(
            'address',
            'organization',
            'ratePlan',
            'parentId',
            'developerCategory',
            'customAttributes',
        );
        foreach (array_keys($data) as $property) {
            if (in_array($property, $excluded_properties)) {
                continue;
            }

            // form the setter method name to invoke setXxxx
            $setter_method = 'set' . ucfirst($property);

            if (method_exists($this, $setter_method)) {
                $this->$setter_method($data[$property]);
            } else {
                self::$logger->notice('No setter method was found for property "' . $property . '"');
            }
        }
        $this->id = $data['id'];
        if (isset($data['address']) && is_array($data['address']) && count($data['address']) > 0) {
            foreach ($data['address'] as $addr_item) {
                $this->addresses[] = new DataStructures\Address($addr_item);
            }
        }

        if (isset($data['organization'])) {
            $organization = new Organization($this->config);
            $organization->loadFromRawData($data['organization']);
            $this->organization = $organization;
        }
        if (isset($data['ratePlan'])) {
            foreach ($data['ratePlan'] as $rate_plan_data) {
                $dev_rate_plan = new DeveloperRatePlan($this->email, $this->config);
                $dev_rate_plan->loadFromRawData($rate_plan_data);
                $this->ratePlan[] = $dev_rate_plan;
            }
        }
        if (isset($data['parentId'])) {
            $parent = new Developer($this->config);
            $parent->loadFromRawData($data['parentId']);
            $this->parentId = $parent;
        }
        if (isset($data['developerCategory'])) {
            $dev_cat = new DeveloperCategory($this->config);
            $dev_cat->loadFromRawData($data['developerCategory']);
            $this->mintDeveloperCategory = $dev_cat;
        }
        if (array_key_exists('customAttributes', $data) && is_array($data['customAttributes'])) {
            foreach ($data['customAttributes'] as $attribute) {
                $this->customAttributes[$attribute['name']] = @$attribute['value'];
            }
        }
    }

    protected function initValues()
    {
        $this->addresses = array();
        $this->mintBillingType = 'PREPAID';
        $this->mintIsBroker = false;
        $this->email = null;
        $this->mintDeveloperLegalName = null;
        $this->name = null;
        $this->mintRegistrationId = null;
        $this->status = 'ACTIVE';
        $this->mintDeveloperType = 'UNTRUSTED';
        $this->customAttributes = array();
    }

    /**
     * {@inheritdoc}
     */
    public function save($save_method = 'auto')
    {
        $developer = new ManagementDeveloper($this->config);
        $developer->load($this->getId());
        $reflect = new \ReflectionClass($this);
        foreach ($reflect->getProperties() as $property) {
            if (strpos($property->getName(), 'mint') !== 0) {
                continue;
            }
            // Generate valid attribute name from property name.
            $property_name_parts = preg_split('/(?=[A-Z])/', $property->getName());
            $property_name_parts = array_map('strtoupper', $property_name_parts);
            $attribute_name = implode('_', $property_name_parts);
            // Make sure that reflection can access to protected properties.
            $property->setAccessible(true);
            $value = $property->getValue($this);
            // Skip null values and object with undefined __toString methods.
            if ($value == null || (is_object($value) && !method_exists($value, '__toString'))) {
                continue;
            }
            $developer->setAttribute($attribute_name, $property->getValue($this));
        }
        // Take special care of MINT_DEVELOPER_ADDRESS attribute.
        if (!empty($this->addresses)) {
            if (count($this->addresses) == 1) {
                $developer->setAttribute('MINT_DEVELOPER_ADDRESS', (string)$this->addresses[0]);
            } else {
                $json_encoded_parts = array();
                foreach ($this->addresses as $address) {
                    $json_encoded_parts[] = (string)$address;
                }
                $developer->setAttribute('MINT_DEVELOPER_ADDRESS', json_encode($json_encoded_parts));
            }
        }
        foreach ($this->customAttributes as $key => $value) {
            $developer->setAttribute($key, $value);
        }
        $developer->save();
    }

    public function __toString()
    {
        $obj = array();
        $obj['address'] = null;
        if (!empty($this->addresses)) {
            if (count($this->addresses) == 1) {
                $obj['address'] = json_decode((string)$this->addresses[0], true);
            } else {
                $json_encoded_parts = array();
                foreach ($this->addresses as $address) {
                    $json_encoded_parts[] = json_decode((string)$address, true);
                }
                $obj['address'] = $json_encoded_parts;
            }
        }
        $obj['organization'] = array('id' => $this->organization->getId());

        $properties = array_keys(get_object_vars($this));
        $excluded_properties = array_keys(get_class_vars(get_parent_class($this)));
        $excluded_properties[] = 'addresses';
        $excluded_properties[] = 'organization';
        foreach ($properties as $property) {
            if (in_array($property, $excluded_properties)) {
                continue;
            }
            if (isset($this->$property)) {
                $obj[$property] = $this->$property;
            }
        }
        return json_encode($obj);
    }

    public function getApplications()
    {
        return new Application($this->email, $this->config);
    }

    public function getBankDetails($refresh = false)
    {
        if (!isset($this->bankDetail) || $refresh) {
            $this->bankDetail = new BankDetail($this->email, $this->config);
            $this->bankDetail->load();
        }
        return $this->bankDetail;
    }

    public function getAcceptedRatePlans()
    {
        $cache_manager = CacheFactory::getCacheManager();
        $data = $cache_manager->get('developer_accepted_rateplan:' . $this->getId(), null);
        if (!isset($data)) {
            $url = rawurlencode($this->email) . '/developer-accepted-rateplans';
            $this->get($url);
            $data = $this->responseObj;
            $cache_manager->set('developer_accepted_rateplan:' . $this->getId(), $data);
        }

        $return_objects = array();
        foreach ($data['developerRatePlan'] as $response_data) {
            $developerRatePlan = new DeveloperRatePlan($this->getEmail(), $this->config);
            $developerRatePlan->loadFromRawData($response_data);
            $return_objects[] = $developerRatePlan;
        }
        return $return_objects;
    }

    public function getPrepaidBalance($month = null, $billingYear = null, $currencyId = null, $ownerId = null)
    {
        $identifier = $ownerId ?: $this->email;

        $month = $month ?: date('F');
        $billingYear = $billingYear ?: date('Y');

        $options = array(
            'query' => array(
                'billingMonth' => strtoupper($month),
                'billingYear' => $billingYear,
                'supportedCurrencyId' => $currencyId,
            ),
        );
        $url = rawurlencode($identifier) . '/prepaid-developer-balance';
        $this->get($url, 'application/json; charset=utf-8', array(), $options);
        $response = $this->responseObj;
        $returnObjects = array();
        foreach ($response['developerBalance'] as $responseData) {
            $obj = new DeveloperBalance($identifier, $this->getConfig());
            $obj->loadFromRawData($responseData);
            $returnObjects[] = $obj;
        }
        return $returnObjects;
    }

    /**
     * Creates a payment request
     *
     * @param array $parameters
     * @param string $address
     * @param array $headers
     * @param string $developer_or_company_id
     *
     * @return \Apigee\Mint\DataStructures\Payment
     * @throws \Apigee\Exceptions\ResponseException
     */
    public function createPayment(array $parameters, $address, array $headers, $developer_or_company_id = null)
    {
        $id = $developer_or_company_id ?: $this->email;

        $options = array(
            'query' => $parameters,
        );
        $url = rawurlencode($id) . '/payment';
        $this->post(
            $url,
            $address,
            'application/xml; charset=utf-8',
            'application/json; charset=utf-8',
            $headers,
            $options
        );
        if ($this->responseCode == 200) {
            // Make sure the response did not fail, where success value is
            // FALSE from WorldPay.
            // TODO: These error responses need to be payment provider agnostic.
            if (isset($this->responseObj['success']) && !$this->responseObj['success']) {
                throw new ResponseException(
                    'Payment server response unsuccessful',
                    $this->responseCode,
                    $url,
                    $options,
                    $this->responseText
                );
            }
            $payment = new Payment($this->responseObj);
            return $payment;
        }
        throw new ResponseException(
            'Payment server response failed',
            $this->responseCode,
            $url,
            $options,
            $this->responseText
        );
    }

    public function topUpPrepaidBalance($new_balance, $developer_or_company_id = null)
    {
        $id = $developer_or_company_id ?: $this->email;
        $url = rawurlencode($id) . '/developer-balances';
        $this->post($url, $new_balance);
    }

    public function getRevenueReport($report)
    {
        $url = '/mint/organizations/'
            . rawurlencode($this->config->orgName)
            . '/developers/'
            . rawurlencode($this->email)
            . '/revenue-reports';
        $content_type = 'application/json; charset=utf-8';
        $accept_type = 'application/octet-stream; charset=utf-8';

        $this->setBaseUrl($url);
        $this->post(null, $report, $content_type, $accept_type);
        $this->restoreBaseUrl();
        $response = $this->responseText;
        return $response;
    }

    public function saveReportDefinition($report_def)
    {
        $url = '/mint/organizations/'
            . rawurlencode($this->config->orgName)
            . '/developers/'
            . rawurlencode($this->email)
            . '/report-definitions';
        $this->setBaseUrl($url);
        $this->post(null, $report_def);
        $this->restoreBaseUrl();
    }

    public function getReportDefinitions()
    {
        $url = '/mint/organizations/'
            . rawurlencode($this->config->orgName)
            . '/developers/'
            . rawurlencode($this->email)
            . '/report-definitions';
        $this->setBaseUrl($url);
        $this->get();
        $this->restoreBaseUrl();
        $data = $this->responseObj;
        $revenue_reports = array();
        foreach ($data['reportDefinition'] as $report) {
            $revenue_report = new RevenueReport($report, $this);
            $revenue_reports[] = $revenue_report;
        }
        return $revenue_reports;
    }

    /**
     * Retrieve the ratePlan associated with a given product
     * @param string $product_id
     *   Product Id
     * @param string $developer_id
     *   Developer email
     * @throws ParameterException
     *   Throw if no developer id is provided or email property is null
     * @throws MintApiException
     *   Throw if Exception is related to Mint API
     * @throws \Exception
     *   Throw if any other exception
     * @return \Apigee\Mint\RatePlan
     *   The RatePlan associated to this Product
     */
    public function getRatePlanByProduct($product_id, $developer_id = null)
    {
        if (empty($developer_id)) {
            if (!empty($this->email)) {
                $developer_id = $this->email;
            } else {
                throw new ParameterException("Developer id not specified");
            }
        }
        try {
            // The /{developer}/products/{product_id}/rate-plan-by-developer-product/
            // resource is under /developer, so we do a GET call to get RatePlan
            // object.
            $url = rawurlencode($developer_id)
                . '/products/'
                . rawurlencode($product_id)
                . '/rate-plan-by-developer-product/';
            $this->get($url);

            // We do not need to make any calls to RatePlan resources, so we
            // pass in a null m_package_id parameter, then use loadFromRawData
            // to create a RatePlan object from the returned response from
            // rate-plan-by-developer-product call above.
            $ratePlan = new RatePlan(null, $this->config);
            $ratePlan->loadFromRawData($this->responseObj);
        } catch (\Exception $e) {
            if ($e instanceof ResponseException && MintApiException::isMintExceptionCode($e)) {
                throw new MintApiException($e);
            } else {
                throw $e;
            }
        }
        return $ratePlan;
    }

    /**
     * Get eligible Mint products for this developer.
     *
     * This function calls the /eligible-products API to find
     * out what products this developer is able to purchase.  If the product
     * is associated to one or more packages, then the product is not displayed
     * unless the developer has purchased a plan that has that product
     * associated to it.
     *
     * @return array of
     * @throws \Apigee\Exceptions\ParameterException
     * @throws \Apigee\Mint\Exceptions\MintApiException
     * @throws \Exception
     */
    public function getEligibleProducts()
    {

        if (!empty($this->email)) {
            $developer_id = $this->email;
        } else {
            throw new ParameterException("Developer id not specified");
        }

        $products = array();
        try {
            $url = rawurlencode($developer_id) . '/eligible-products';
            $this->get($url);
            $data = $this->responseObj;
            foreach ($data['product'] as $product) {
                unset($product['organization']);
                $products[$product['name']] = $product;
            }

        } catch (\Exception $e) {
            if ($e instanceof ResponseException && MintApiException::isMintExceptionCode($e)) {
                throw new MintApiException($e);
            } else {
                throw $e;
            }
        }
        return $products;
    }

    /*
     * accessors (getters/setters)
     */

    public function getApproxTaxRate()
    {
        return $this->mintApproxTaxRate;
    }

    public function setApproxTaxRate($approx_tax_rate)
    {
        $this->mintApproxTaxRate = $approx_tax_rate;
    }

    public function getAddresses()
    {
        return $this->addresses;
    }

    public function addAddress(DataStructures\Address $address)
    {
        $this->addresses[] = $address;
    }

    public function clearAddresses()
    {
        $this->addresses = array();
    }

    public function getBillingProfile()
    {
        return $this->billingProfile;
    }

    public function setBillingProfile($billing_profile)
    {
        $this->billingProfile = $billing_profile;
    }

    public function getBillingType()
    {
        return $this->mintBillingType;
    }

    public function setBillingType($type)
    {
        $this->mintBillingType = BillingType::get($type);
    }

    /**
     * @return \Apigee\Mint\DeveloperCategory
     */
    public function getDeveloperCategory()
    {
        return $this->mintDeveloperCategory;
    }

    /**
     * @param \Apigee\Mint\DeveloperCategory $dev_category
     */
    public function setDeveloperCategory(DeveloperCategory $dev_category)
    {
        $this->mintDeveloperCategory = $dev_category;
    }

    public function getDeveloperRole()
    {
        return $this->developerRole;
    }

    public function setDeveloperRole($developer_role)
    {
        $this->developerRole = $developer_role;
    }

    public function isBroker()
    {
        return $this->mintIsBroker;
    }

    public function setBroker($bool = true)
    {
        $this->mintIsBroker = (bool)$bool;
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function setEmail($email)
    {
        // TODO: validate
        $this->email = $email;
    }

    public function hasSelfBilling()
    {
        return $this->mintHasSelfBilling;
    }

    public function setHasSelfBilling($has_self_billing)
    {
        $this->mintHasSelfBilling = $has_self_billing;
    }

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function getLegalName()
    {
        return $this->mintDeveloperLegalName;
    }

    public function setLegalName($name)
    {
        $this->mintDeveloperLegalName = $name;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * @return \Apigee\Mint\Organization
     */
    public function getOrganization()
    {
        return $this->organization;
    }

    /**
     * @param \Apigee\Mint\Organization $organization
     */
    public function setOrganization(Organization $organization)
    {
        $this->organization = $organization;
    }

    /**
     * @return \Apigee\Mint\Developer
     */
    public function getParentId()
    {
        return $this->parentId;
    }

    /**
     * @param \Apigee\Mint\Developer $developer
     */
    public function setParentId(Developer $developer)
    {
        $this->parentId = $developer;
    }

    public function getPhone()
    {
        return $this->mintDeveloperPhone;
    }

    public function setPhone($phone)
    {
        $this->mintDeveloperPhone = $phone;
    }

    public function getRatePlan()
    {
        return $this->ratePlan;
    }

    public function addRatePlan($rate_plan)
    {
        $this->ratePlan[] = $rate_plan;
    }

    public function getRegistrationId()
    {
        return $this->mintRegistrationId;
    }

    public function setRegistrationId($id)
    {
        // TODO: validate
        $this->id = $id;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($status)
    {
        $this->status = DeveloperStatusType::get($status);
    }

    public function getTaxExemptAuthNo()
    {
        return $this->mintTaxExemptAuthNo;
    }

    public function setTaxExemptAuthNo($tax_exempt_auth_no)
    {
        $this->mintTaxExemptAuthNo = $tax_exempt_auth_no;
    }

    public function getType()
    {
        return $this->mintDeveloperType;
    }

    public function setType($type)
    {
        $this->mintDeveloperType = DeveloperType::get($type);
    }

    /**
     * Returns an attribute associated with the developer, or null if the
     * attribute does not exist.
     *
     * @param string $attr
     * @return string|null
     */
    public function getCustomAttribute($attr)
    {
        if (array_key_exists($attr, $this->customAttributes)) {
            return $this->customAttributes[$attr];
        }
        return null;
    }

    /**
     * Sets an attribute on the developer.
     *
     * @param string $attr
     * @param string $value
     */
    public function setCustomAttribute($attr, $value)
    {
        $this->customAttributes[$attr] = (string)$value;
    }

    /**
     * Returns the attributes associated with the developer.
     *
     * @return string[]
     */
    public function getCustomAttributes()
    {
        return $this->customAttributes;
    }
}
