<?php
/**
 * Currency Prices plugin for Craft CMS 3.x
 *
 * Adds payment currency prices to products
 *
 * @link      https://kurious.agency
 * @copyright Copyright (c) 2018 Kurious Agency
 */

namespace kuriousagency\commerce\currencyprices\adjusters;

use kuriousagency\commerce\currencyprices\CurrencyPrices;
use kuriousagency\commerce\currencyprices\models\ShippingRule;


use Craft;
use craft\base\Component;
use craft\commerce\base\AdjusterInterface;
use craft\commerce\elements\Order;
use craft\commerce\events\DiscountAdjustmentsEvent;
use craft\commerce\helpers\Currency;
use craft\commerce\models\Discount as DiscountModel;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\Plugin;
use craft\commerce\records\Discount as DiscountRecord;

/**
 * Discount Adjuster
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class Discount extends Component implements AdjusterInterface
{
    // Constants
    // =========================================================================

    /**
     * The discount adjustment type.
     */
    const ADJUSTMENT_TYPE = 'discount';

    /**
     * @event DiscountAdjustmentsEvent The event that is raised after a discount has matched the order and before it returns it's adjustments.
     *
     * Plugins can get notified before a line item is being saved
     *
     * ```php
     * use craft\commerce\adjusters\Discount;
     * use yii\base\Event;
     *
     * Event::on(Discount::class, Discount::EVENT_AFTER_DISCOUNT_ADJUSTMENTS_CREATED, function(DiscountAdjustmentsEvent $e) {
     *     // Do something - perhaps use a 3rd party to check order data and cancel all adjustments for this discount or modify the adjustments.
     * });
     * ```
     */
    const EVENT_AFTER_DISCOUNT_ADJUSTMENTS_CREATED = 'afterDiscountAdjustmentsCreated';


    // Properties
    // =========================================================================

    /**
     * @var Order
     */
    private $_order;

    /**
     * @var
     */
    private $_discount;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function adjust(Order $order): array
    {
        $this->_order = $order;

        $discounts = Plugin::getInstance()->getDiscounts()->getAllDiscounts();

        // Find discounts with no coupon or the coupon that matches the order.
        $availableDiscounts = [];
        foreach ($discounts as $discount) {

            if (!$discount->enabled) {
                continue;
            }

            if ($discount->code == null) {
                $availableDiscounts[] = $discount;
                continue;
            }

            if ($this->_order->couponCode && (strcasecmp($this->_order->couponCode, $discount->code) == 0)) {
                $availableDiscounts[] = $discount;
            }
        }

        $adjustments = [];

		$items = [];
        foreach ($availableDiscounts as $discount) {
            $newAdjustments = $this->_getAdjustments($discount);
            if ($newAdjustments) {
				$process = false;
				foreach ($newAdjustments as $ad)
				{
					if ($discount->stopProcessing && $ad->lineItem) {
						if (!in_array($ad->lineItem->id, $items)) {
							$items[] = $ad->lineItem->id;
							$adjustments = array_merge($adjustments, [$ad]);
						}
						$process = true;
					} else {
						$adjustments = array_merge($adjustments, [$ad]);
					}
				}
                //$adjustments = array_merge($adjustments, $newAdjustments);

                if ($discount->stopProcessing && !$process) {
					break;
                }
            }
        }

        return $adjustments;
    }

    // Private Methods
    // =========================================================================

    /**
     * @param DiscountModel $discount
     * @return OrderAdjustment
     */
    private function _createOrderAdjustment(DiscountModel $discount): OrderAdjustment
    {
        //preparing model
        $adjustment = new OrderAdjustment();
        $adjustment->type = self::ADJUSTMENT_TYPE;
        $adjustment->name = $discount->name;
        $adjustment->orderId = $this->_order->id;
        $adjustment->description = $discount->description;
        $adjustment->sourceSnapshot = $discount->attributes;

        return $adjustment;
    }

    /**
     * @param DiscountModel $discount
     * @return OrderAdjustment[]|false
     */
    private function _getAdjustments(DiscountModel $discount)
    {
        $adjustments = [];

        $this->_discount = $discount;

        $now = new \DateTime();
        $from = $this->_discount->dateFrom;
        $to = $this->_discount->dateTo;
        if (($from && $from > $now) || ($to && $to < $now)) {
            return false;
        }

        //checking items
        $matchingQty = 0;
        $matchingTotal = 0;
        $matchingLineIds = [];
        foreach ($this->_order->getLineItems() as $item) {
            if (Plugin::getInstance()->getDiscounts()->matchLineItem($item, $this->_discount)) {
                if (!$this->_discount->allGroups) {
                    $customer = $this->_order->getCustomer();
                    $user = $customer ? $customer->getUser() : null;
                    $userGroups = Plugin::getInstance()->getCustomers()->getUserGroupIdsForUser($user);
                    if ($user && array_intersect($userGroups, $this->_discount->getUserGroupIds())) {
                        $matchingLineIds[] = $item->id;
                        $matchingQty += $item->qty;
                        $matchingTotal += $item->getSubtotal();
                    }
                } else {
                    $matchingLineIds[] = $item->id;
                    $matchingQty += $item->qty;
                    $matchingTotal += $item->getSubtotal();
                }
            }
        }

        if (!$matchingQty) {
            return false;
        }

        // Have they entered a max qty?
        if ($this->_discount->maxPurchaseQty > 0 && $matchingQty > $this->_discount->maxPurchaseQty) {
            return false;
        }

        // Reject if they have not added enough matching items
        if ($matchingQty < $this->_discount->purchaseQty) {
            return false;
        }

        // Reject if the matching items values is not enough
        if ($matchingTotal < $this->_discount->purchaseTotal) {
            return false;
		}
		
		$price = CurrencyPrices::$plugin->service->getPricesByDiscountIdAndCurrency($this->_discount->id, $this->_order->paymentCurrency);
		if ($price) $price = (object) $price;
//Craft::dd($price);
        foreach ($this->_order->getLineItems() as $item) {
            if (in_array($item->id, $matchingLineIds, false)) {
                $adjustment = $this->_createOrderAdjustment($this->_discount);
				$adjustment->setLineItem($item);
				
                $amountPerItem = ($item->qty * $item->salePrice) - Currency::round(($price ? $price->perItemDiscount : $this->_discount->perItemDiscount) * $item->qty);

                //Default is percentage off already discounted price
                $existingLineItemDiscount = $item->getAdjustmentsTotalByType('discount');
                $existingLineItemPrice = ($item->getSubtotal() + $existingLineItemDiscount);
                $amountPercentage = Currency::round($this->_discount->percentDiscount * $existingLineItemPrice);

                if ($this->_discount->percentageOffSubject == DiscountRecord::TYPE_ORIGINAL_SALEPRICE) {
                    $amountPercentage = Currency::round($this->_discount->percentDiscount * $item->getSubtotal());
                }

                $adjustment->amount = 0-($amountPerItem + $amountPercentage);

                if ($adjustment->amount != 0) {
                    $adjustments[] = $adjustment;
                }
            }
		}

        foreach ($this->_order->getLineItems() as $item) {
            if (in_array($item->id, $matchingLineIds, false) && $discount->freeShipping) {
                $adjustment = $this->_createOrderAdjustment($this->_discount);
                $shippingCost = $item->getAdjustmentsTotalByType('shipping');
                if ($shippingCost > 0) {
                    $adjustment->setLineItem($item);
                    $adjustment->amount = $shippingCost * -1;
                    $adjustment->description = Craft::t('commerce', 'Remove Shipping Cost');
                    $adjustments[] = $adjustment;
                }
            }
        }

        if ($discount->baseDiscount !== null && $discount->baseDiscount != 0) {
            $baseDiscountAdjustment = $this->_createOrderAdjustment($discount);
            $baseDiscountAdjustment->amount = 0-($price ? $price->baseDiscount : $discount->baseDiscount);
            $adjustments[] = $baseDiscountAdjustment;
        }

        // only display adjustment if an amount was calculated
        if (!count($adjustments)) {
            return false;
        }

        // Raise the 'beforeMatchLineItem' event
        $event = new DiscountAdjustmentsEvent([
            'order' => $this->_order,
            'discount' => $discount,
            'adjustments' => $adjustments
        ]);

        $this->trigger(self::EVENT_AFTER_DISCOUNT_ADJUSTMENTS_CREATED, $event);

        if (!$event->isValid) {
            return false;
        }

        return $event->adjustments;
    }
}
