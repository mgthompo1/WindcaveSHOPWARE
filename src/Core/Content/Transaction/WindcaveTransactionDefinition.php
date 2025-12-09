<?php

declare(strict_types=1);

namespace Windcave\Core\Content\Transaction;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

/**
 * Windcave Transaction Entity Definition
 *
 * Stores Windcave-specific transaction data for payment history tracking.
 * Links to OrderTransaction for Shopware integration.
 */
class WindcaveTransactionDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'windcave_transaction';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return WindcaveTransactionEntity::class;
    }

    public function getCollectionClass(): string
    {
        return WindcaveTransactionCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            // Primary key
            (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey(), new ApiAware()),

            // Foreign key to Shopware order transaction
            (new FkField('order_transaction_id', 'orderTransactionId', OrderTransactionDefinition::class))
                ->addFlags(new Required(), new ApiAware()),

            // Windcave session ID
            (new StringField('session_id', 'sessionId'))
                ->addFlags(new ApiAware()),

            // Windcave transaction ID (from payment response)
            (new StringField('windcave_transaction_id', 'windcaveTransactionId'))
                ->addFlags(new ApiAware()),

            // Transaction type: purchase, refund, auth, complete, void
            (new StringField('transaction_type', 'transactionType'))
                ->addFlags(new Required(), new ApiAware()),

            // Transaction status: approved, declined, pending, error
            (new StringField('status', 'status'))
                ->addFlags(new Required(), new ApiAware()),

            // Response code from Windcave
            (new StringField('response_code', 'responseCode'))
                ->addFlags(new ApiAware()),

            // Response text from Windcave
            (new StringField('response_text', 'responseText'))
                ->addFlags(new ApiAware()),

            // Amount in minor units
            (new FloatField('amount', 'amount'))
                ->addFlags(new ApiAware()),

            // Currency code
            (new StringField('currency', 'currency'))
                ->addFlags(new ApiAware()),

            // Card scheme (visa, mastercard, amex, etc.)
            (new StringField('card_scheme', 'cardScheme'))
                ->addFlags(new ApiAware()),

            // Masked card number (last 4 digits)
            (new StringField('card_number_masked', 'cardNumberMasked'))
                ->addFlags(new ApiAware()),

            // Card expiry (MM/YY)
            (new StringField('card_expiry', 'cardExpiry'))
                ->addFlags(new ApiAware()),

            // Card token for rebilling
            (new StringField('card_token', 'cardToken'))
                ->addFlags(new ApiAware()),

            // 3DS status if applicable
            (new StringField('three_ds_status', 'threeDsStatus'))
                ->addFlags(new ApiAware()),

            // RRN (Retrieval Reference Number)
            (new StringField('rrn', 'rrn'))
                ->addFlags(new ApiAware()),

            // Auth code
            (new StringField('auth_code', 'authCode'))
                ->addFlags(new ApiAware()),

            // Payment mode used (dropin, hostedfields, hpp)
            (new StringField('payment_mode', 'paymentMode'))
                ->addFlags(new ApiAware()),

            // Test mode flag
            (new StringField('test_mode', 'testMode'))
                ->addFlags(new ApiAware()),

            // Raw response JSON for debugging
            (new StringField('raw_response', 'rawResponse'))
                ->addFlags(new ApiAware()),

            // Association to order transaction
            new ManyToOneAssociationField('orderTransaction', 'order_transaction_id', OrderTransactionDefinition::class, 'id', false),
        ]);
    }
}
