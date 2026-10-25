<?php

namespace App\Provider;

use App\Dto\ContentPageDto;
use Batteryincluded\BatteryincludedBundle\Provider\PartialDataProviderInterface;
use Generator;

/**
 * Content pages in german and english, an example of an own document type with translations.
 */
class ContentPageProvider implements PartialDataProviderInterface
{
    // slug => locale => title, teaser, content (paragraphs separated by a blank line)
    private const PAGES = [
        'shipping' => [
            'de' => ['Versand & Lieferung', 'Kostenloser Versand ab 50 € innerhalb von 1–3 Werktagen.', "Wir versenden alle Smartphones, Tablets und Laptops versichert mit DHL. Bestellungen bis 14 Uhr verlassen unser Lager noch am selben Tag.\n\nDie Lieferung nach Deutschland ist ab einem Bestellwert von 50 € kostenlos, nach Österreich und in die Schweiz berechnen wir eine Pauschale von 9,90 €."],
            'en' => ['Shipping & delivery', 'Free shipping from € 50 within 1–3 working days.', "We ship all smartphones, tablets and laptops insured with DHL. Orders placed before 2 pm leave our warehouse on the same day.\n\nDelivery within Germany is free from an order value of € 50, for Austria and Switzerland we charge a flat rate of € 9.90."],
        ],
        'returns' => [
            'de' => ['Rückgabe & Widerruf', '30 Tage Rückgaberecht auf alle Geräte.', "Du kannst jedes Gerät innerhalb von 30 Tagen nach Erhalt ohne Angabe von Gründen zurückgeben. Das Rücksendeetikett erstellst du in deinem Kundenkonto.\n\nDie Erstattung erfolgt innerhalb von 5 Werktagen nach Eingang der Rücksendung über die ursprüngliche Zahlungsart."],
            'en' => ['Returns & cancellation', '30 days return policy on all devices.', "You can return every device within 30 days of receipt without giving reasons. Create the return label in your customer account.\n\nThe refund is made within 5 working days after we received the return, using the original payment method."],
        ],
        'payment' => [
            'de' => ['Zahlungsarten', 'Rechnung, Kreditkarte, PayPal und Ratenkauf.', "Bezahle bequem per Rechnung, Kreditkarte, PayPal, Apple Pay oder Vorkasse.\n\nFür Bestellungen ab 200 € bieten wir einen Ratenkauf mit bis zu 24 Monaten Laufzeit an."],
            'en' => ['Payment methods', 'Invoice, credit card, PayPal and instalments.', "Pay conveniently by invoice, credit card, PayPal, Apple Pay or prepayment.\n\nFor orders from € 200 we offer instalments with a term of up to 24 months."],
        ],
        'warranty' => [
            'de' => ['Garantie & Reparatur', '2 Jahre Garantie und Reparaturservice mit Leihgerät.', "Auf alle Geräte gewähren wir 2 Jahre Garantie. Bei einem Defekt holen wir das Gerät kostenlos ab.\n\nWährend der Reparatur erhältst du auf Wunsch ein Leihgerät, damit du erreichbar bleibst."],
            'en' => ['Warranty & repair', '2 years warranty and repair service with a loan device.', "All devices come with a 2 year warranty. If a device is defective, we pick it up free of charge.\n\nOn request you get a loan device during the repair, so you stay reachable."],
        ],
        'about-us' => [
            'de' => ['Über uns', 'Seit 2015 dein Spezialist für mobile Geräte.', "BatteryIncluded ist ein Familienunternehmen aus Münster. Unser Team berät dich persönlich bei der Wahl des passenden Geräts.\n\nAlle Geräte prüfen wir vor dem Versand in unserer eigenen Werkstatt."],
            'en' => ['About us', 'Your specialist for mobile devices since 2015.', "BatteryIncluded is a family business from Münster. Our team advises you personally on choosing the right device.\n\nWe check every device in our own workshop before shipping."],
        ],
        'contact' => [
            'de' => ['Kontakt', 'Hotline, E-Mail und Chat, Montag bis Freitag von 8 bis 18 Uhr.', "Unser Kundenservice ist Montag bis Freitag von 8 bis 18 Uhr per Telefon, E-Mail und Chat für dich da.\n\nFür Fragen zu einer Bestellung halte bitte deine Bestellnummer bereit."],
            'en' => ['Contact', 'Hotline, email and chat, Monday to Friday from 8 am to 6 pm.', "Our customer service is available Monday to Friday from 8 am to 6 pm by phone, email and chat.\n\nFor questions about an order, please have your order number ready."],
        ],
    ];

    private const URLS = [
        'de' => 'https://www.batteryincluded.io/de/%s/',
        'en' => 'https://www.batteryincluded.io/en/%s/',
    ];

    // the test data is generated, a shop would load only the requested ids from its database
    public function getByIds(array $ids): iterable
    {
        return array_filter($this->generatePages(), static fn ($document) => in_array($document->getIdentifier(), $ids, true));
    }

    public function getBatches(int $batchSize): Generator
    {
        yield from array_chunk($this->generatePages(), $batchSize);
    }

    /**
     * @return ContentPageDto[]
     */
    private function generatePages(): array
    {
        $pages = [];
        $id = 0;
        foreach (self::PAGES as $slug => $texts) {
            $id++;
            [$title, $teaser, $content] = $texts['de'];
            // the german texts are the fallback for languages without translation
            $page = (new ContentPageDto((string) $id))
                ->setTitle($title)
                ->setTeaser($teaser)
                ->setContent($content)
                ->setUrl(sprintf(self::URLS['de'], $slug))
                ->setActive(true);

            // every locale is exported to the _CONTENT_i18n root node
            foreach ($texts as $locale => [$title, $teaser, $content]) {
                $page
                    ->addTranslation($locale, 'title', $title)
                    ->addTranslation($locale, 'teaser', $teaser)
                    ->addTranslation($locale, 'content', $content)
                    ->addTranslation($locale, 'url', sprintf(self::URLS[$locale], $slug));
            }
            $pages[] = $page;
        }

        return $pages;
    }
}
