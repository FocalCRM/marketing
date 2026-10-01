<?php

declare(strict_types=1);

namespace Focal\Marketing\Tests;

use Focal\Core\Models\Contact;
use Focal\Marketing\Models\MarketingSubscription;
use Focal\Marketing\Models\MarketingSubscriptionTopic;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MarketingSubscriptionTopicTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_topic_and_evaluate_default_subscription(): void
    {
        $topic = MarketingSubscriptionTopic::create([
            'name' => 'Product Updates',
            'slug' => 'product_updates',
            'description' => 'Changelogs and new feature releases',
            'is_default' => true,
        ]);

        $this->assertTrue(MarketingSubscriptionTopic::isSubscribed('lead@example.com', $topic->id));
        $this->assertFalse(MarketingSubscription::isSuppressed('lead@example.com', $topic->id));
    }

    public function test_selective_topic_unsubscribing(): void
    {
        $topicMarketing = MarketingSubscriptionTopic::create([
            'name' => 'Promotional Offers',
            'slug' => 'promos',
            'is_default' => true,
        ]);

        $topicSecurity = MarketingSubscriptionTopic::create([
            'name' => 'Security Bulletins',
            'slug' => 'security',
            'is_default' => true,
        ]);

        // Unsubscribe only from promos
        MarketingSubscriptionTopic::setSubscription('user@acme.com', $topicMarketing->id, false);

        // Promos should be suppressed
        $this->assertTrue(MarketingSubscription::isSuppressed('user@acme.com', $topicMarketing->id));
        $this->assertFalse(MarketingSubscriptionTopic::isSubscribed('user@acme.com', $topicMarketing->id));

        // Security bulletins should NOT be suppressed
        $this->assertFalse(MarketingSubscription::isSuppressed('user@acme.com', $topicSecurity->id));
        $this->assertTrue(MarketingSubscriptionTopic::isSubscribed('user@acme.com', $topicSecurity->id));
    }

    public function test_global_unsubscribe_overrides_all_topics(): void
    {
        $topic = MarketingSubscriptionTopic::create([
            'name' => 'Weekly Newsletter',
            'slug' => 'newsletter',
            'is_default' => true,
        ]);

        // Contact opted in to topic
        MarketingSubscriptionTopic::setSubscription('optout@acme.com', $topic->id, true);

        // But globally unsubscribed
        MarketingSubscription::unsubscribe('optout@acme.com');

        // Both general and topic checks must report suppressed
        $this->assertTrue(MarketingSubscription::isSuppressed('optout@acme.com'));
        $this->assertTrue(MarketingSubscription::isSuppressed('optout@acme.com', $topic->id));
    }

    public function test_preference_center_updates_topic_subscriptions(): void
    {
        $contact = Contact::create([
            'first_name' => 'Sarah',
            'last_name' => 'Connor',
            'email' => 'sarah@cyberdyne.test',
            'marketing_verification_token' => 'pref_tok_999',
        ]);

        $topic1 = MarketingSubscriptionTopic::create(['name' => 'Digest', 'slug' => 'digest', 'is_default' => true]);
        $topic2 = MarketingSubscriptionTopic::create(['name' => 'Promos', 'slug' => 'promos', 'is_default' => true]);

        // Submit preferences selecting only 'digest'
        $response = $this->post('/marketing/preferences/pref_tok_999', [
            'topics' => ['digest'],
        ]);

        $response->assertRedirect();

        $this->assertTrue(MarketingSubscriptionTopic::isSubscribed('sarah@cyberdyne.test', $topic1->id));
        $this->assertFalse(MarketingSubscriptionTopic::isSubscribed('sarah@cyberdyne.test', $topic2->id));
    }
}
