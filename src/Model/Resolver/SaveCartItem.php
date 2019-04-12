<?php
/**
 * ScandiPWA - Progressive Web App for Magento
 *
 * Copyright © Scandiweb, Inc. All rights reserved.
 * See LICENSE for license details.
 *
 * @license OSL-3.0 (Open Software License ("OSL") v. 3.0)
 * @package scandipwa/quote-graphql
 * @link    https://github.com/scandipwa/quote-graphql
 */

declare(strict_types=1);


namespace ScandiPWA\QuoteGraphQl\Model\Resolver;


use Magento\ConfigurableProduct\Model\Quote\Item\ConfigurableItemOptionValueFactory;
use Magento\Quote\Model\Quote\ProductOptionFactory;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Query\Resolver\Value;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Api\CartItemRepositoryInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartItemInterface;
use \Magento\Quote\Api\Data\CartItemInterfaceFactory;
use Magento\Quote\Api\Data\ProductOptionExtensionFactory;
use Magento\Quote\Api\GuestCartItemRepositoryInterface;
use Magento\Quote\Model\QuoteIdMaskFactory;

/**
 * Class SaveCartItem
 * @package ScandiPWA\QuoteGraphQl\Model\Resolver
 */
class SaveCartItem implements ResolverInterface
{
    /**
     * @var CartItemRepositoryInterface
     */
    private $cartItemRepository;
    
    /**
     * @var CartItemInterfaceFactory
     */
    private $cartItemFactory;
    
    /**
     * @var GuestCartItemRepositoryInterface
     */
    private $guestCartItemRepository;
    
    /**
     * @var QuoteIdMaskFactory
     */
    private $quoteIdMaskFactory;
    
    /**
     * @var CartRepositoryInterface
     */
    private $quoteRepository;
    
    /**
     * @var ProductOptionFactory
     */
    private $productOptionFactory;
    
    /**
     * @var ProductOptionExtensionFactory
     */
    private $productOptionExtensionFactory;
    
    /**
     * @var ConfigurableItemOptionValueFactory
     */
    private $configurableItemOptionValueFactory;
    
    /**
     * SaveCartItem constructor.
     * @param CartItemRepositoryInterface        $cartItemRepository
     * @param CartItemInterfaceFactory           $cartItemFactory
     * @param GuestCartItemRepositoryInterface   $guestCartItemRepository
     * @param QuoteIdMaskFactory                 $quoteIdMaskFactory
     * @param CartRepositoryInterface            $quoteRepository
     * @param ProductOptionFactory               $productOptionFactory
     * @param ProductOptionExtensionFactory      $productOptionExtensionFactory
     * @param ConfigurableItemOptionValueFactory $configurableItemOptionValueFactory
     */
    public function __construct(
        CartItemRepositoryInterface $cartItemRepository,
        CartItemInterfaceFactory $cartItemFactory,
        GuestCartItemRepositoryInterface $guestCartItemRepository,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        CartRepositoryInterface $quoteRepository,
        ProductOptionFactory $productOptionFactory,
        ProductOptionExtensionFactory $productOptionExtensionFactory,
        ConfigurableItemOptionValueFactory $configurableItemOptionValueFactory
    
    )
    {
        $this->cartItemRepository = $cartItemRepository;
        $this->cartItemFactory = $cartItemFactory;
        $this->guestCartItemRepository = $guestCartItemRepository;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->quoteRepository = $quoteRepository;
        $this->productOptionFactory = $productOptionFactory;
        $this->productOptionExtensionFactory = $productOptionExtensionFactory;
        $this->configurableItemOptionValueFactory = $configurableItemOptionValueFactory;
    }
    
    /**
     * @param Int    $item_id
     * @param String $cartId
     * @return mixed
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function getCartItem(Int $item_id, String $cartId): CartItemInterface
    {
        $quoteIdMask = $this->quoteIdMaskFactory->create()->load($cartId, 'masked_id');
        $quote = $this->quoteRepository->getActive($quoteIdMask->getQuoteId());
        
        return $quote->getItemById($item_id);
    }
    
    /**
     * @param CartItemInterface $cartItem
     * @param array             $productOptions
     */
    private function createConfigurable(CartItemInterface $cartItem, array $productOptions): CartItemInterface
    {
        $extensionAttributes = $productOptions['extension_attributes'];
        
        $attributes = [];
        foreach ($extensionAttributes['configurable_item_options'] as $attribute) {
            $attributes[] = $this->configurableItemOptionValueFactory->create(['data' => $attribute]);
        }
        
        $ext = $this->productOptionExtensionFactory->create(
            ['data' => ['configurable_item_options' => $attributes]]);
        
        
        $options = $this->productOptionFactory->create(['data' => ['extension_attributes' => $ext]]);
        
        return $cartItem->setProductOption($options);
    }
    
    /**
     * @param array $args
     * @return CartItemInterface
     */
    public function createCartItem(array $args): CartItemInterface
    {
        $cartItem = $this->cartItemFactory->create(
            [
                'data' => [
                    CartItemInterface::KEY_SKU => $args['sku'],
                    CartItemInterface::KEY_QTY => $args['qty'],
                    CartItemInterface::KEY_QUOTE_ID => $args['quote_id']
                ]
            ]
        );
        if (array_key_exists(CartItemInterface::KEY_PRODUCT_OPTION, $args)) {
            $cartItem = $this->createConfigurable($cartItem, $args[CartItemInterface::KEY_PRODUCT_OPTION]);
        }
        
        return $cartItem;
    }
    
    /**
     * Fetches the data from persistence models and format it according to the GraphQL schema.
     *
     * @param \Magento\Framework\GraphQl\Config\Element\Field $field
     * @param ContextInterface                                $context
     * @param ResolveInfo                                     $info
     * @param array|null                                      $value
     * @param array|null                                      $args
     * @return mixed|Value
     * @throws \Exception
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    )
    {
        $requestCartItem = $args['cartItem'];
        ['qty' => $qty] = $requestCartItem;
        
        if (array_key_exists('item_id', $requestCartItem)) {
            $item_id = $requestCartItem['item_id'];
            $requestCartItem = $this->getCartItem($item_id, $requestCartItem['quote_id']);
            if ($qty > 0) {
                $requestCartItem->setQty($qty);
            }
            $result = $this->cartItemRepository->save($requestCartItem);
        } else {
            $cartItem = $this->createCartItem($requestCartItem);
            $result = $this->guestCartItemRepository->save($cartItem);
        }
        return array_merge($result->getData(), ['product' => $result->getProduct()->getData()]);
    }
}