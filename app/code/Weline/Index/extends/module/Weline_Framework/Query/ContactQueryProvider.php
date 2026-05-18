<?php

declare(strict_types=1);

namespace Weline\Index\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Index\Model\Contact;

class ContactQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly Contact $contact
    ) {
    }

    public function getProviderName(): string
    {
        return 'contact';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'submit' => $this->submit($params),
            default => throw new \InvalidArgumentException((string)__('Unsupported contact operation: %{1}', $operation)),
        };
    }

    private function submit(array $params): array
    {
        $name = \trim((string)($params['name'] ?? ''));
        $email = \trim((string)($params['email'] ?? ''));
        $subject = \trim((string)($params['subject'] ?? ''));
        $comments = \trim((string)($params['comments'] ?? ''));
        $phone = \trim((string)($params['phone'] ?? ''));

        if ($name === '' || $email === '' || $subject === '' || $comments === '') {
            return [
                'success' => false,
                'message' => (string)__('Name, email, subject and comments are required.'),
            ];
        }

        if (!\filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => (string)__('Invalid email address.'),
            ];
        }

        $contact = clone $this->contact;
        $contact->clear()->load(Contact::schema_fields_EMAIL, $email);
        $contact->setData(Contact::schema_fields_EMAIL, $email);
        $contact->setData(Contact::schema_fields_NAME, \mb_substr($name, 0, 255));
        $contact->setData(Contact::schema_fields_PHONE, \mb_substr($phone, 0, 32));
        $contact->setData(Contact::schema_fields_OBJECT, \mb_substr($subject, 0, 255));
        $contact->setData(Contact::schema_fields_MESSAGE, $comments);
        $contact->save(true, Contact::schema_fields_EMAIL);

        return [
            'success' => true,
            'message' => (string)__('Thanks, your message has been submitted.'),
        ];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'contact',
            'name' => __('Contact query provider'),
            'description' => __('Provides storefront contact form operations.'),
            'module' => 'Weline_Index',
            'operations' => [
                [
                    'name' => 'submit',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 2,
                    'description' => __('Submit storefront contact form.'),
                    'params' => [
                        ['name' => 'name', 'type' => 'string', 'required' => true, 'max_length' => 255],
                        ['name' => 'email', 'type' => 'string', 'required' => true, 'max_length' => 255],
                        ['name' => 'phone', 'type' => 'string', 'required' => false, 'max_length' => 32],
                        ['name' => 'subject', 'type' => 'string', 'required' => true, 'max_length' => 255],
                        ['name' => 'comments', 'type' => 'string', 'required' => true, 'max_length' => 16000],
                    ],
                    'returns' => ['type' => 'array'],
                ],
            ],
        ];
    }
}
