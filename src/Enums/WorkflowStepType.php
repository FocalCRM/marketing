<?php

declare(strict_types=1);

namespace Odden\Marketing\Enums;

enum WorkflowStepType: string
{
    case SendEmail = 'send_email';
    case SendSms = 'send_sms';
    case Delay = 'delay';
    case Condition = 'condition';
    case UpdateContact = 'update_contact';
    case AssignOwner = 'assign_owner';
    case CreateDeal = 'create_deal';
    case CreateSalesTask = 'create_sales_task';
    case InternalNotification = 'internal_notification';
    case Webhook = 'webhook';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::SendEmail => 'Send Marketing Email',
            self::SendSms => 'Send SMS Message',
            self::Delay => 'Delay / Wait Timer',
            self::Condition => 'Evaluate Condition',
            self::UpdateContact => 'Update Contact Property',
            self::AssignOwner => 'Assign Sales Rep Owner',
            self::CreateDeal => 'Create Pipeline Deal',
            self::CreateSalesTask => 'Create Priority Sales Task',
            self::InternalNotification => 'Send Internal Team Alert',
            self::Webhook => 'Trigger Outbound Webhook',
        };
    }
}
