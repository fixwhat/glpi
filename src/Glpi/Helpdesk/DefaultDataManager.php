<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI - Gestionnaire Libre de Parc Informatique
 *
 * http://glpi-project.org
 *
 * @copyright 2015-2025 Teclib' and contributors.
 * @copyright 2003-2014 by the INDEPNET Development Team.
 * @licence   https://www.gnu.org/licenses/gpl-3.0.html
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * ---------------------------------------------------------------------
 */

namespace Glpi\Helpdesk;

use Entity;
use Glpi\Form\Destination\CommonITILField\ContentField;
use Glpi\Form\Destination\CommonITILField\ITILActorFieldStrategy;
use Glpi\Form\Destination\CommonITILField\ObserverField;
use Glpi\Form\Destination\CommonITILField\ObserverFieldConfig;
use Glpi\Form\Destination\CommonITILField\RequestTypeField;
use Glpi\Form\Destination\CommonITILField\RequestTypeFieldConfig;
use Glpi\Form\Destination\CommonITILField\RequestTypeFieldStrategy;
use Glpi\Form\Destination\CommonITILField\SimpleValueConfig;
use Glpi\Form\Destination\CommonITILField\TitleField;
use Glpi\Form\Form;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\QuestionTypeItemDropdown;
use Glpi\Form\QuestionType\QuestionTypeLongText;
use Glpi\Form\QuestionType\QuestionTypeObserver;
use Glpi\Form\QuestionType\QuestionTypeShortText;
use Glpi\Form\QuestionType\QuestionTypeUrgency;
use Glpi\Form\QuestionType\QuestionTypeUserDevice;
use Glpi\Form\Section;
use Glpi\Form\Tag\AnswerTagProvider;
use Glpi\Helpdesk\Tile\FormTile;
use Glpi\Helpdesk\Tile\GlpiPageTile;
use Glpi\Helpdesk\Tile\TilesManager;
use ITILCategory;
use Location;
use RuntimeException;
use Session;
use Ticket;

use function Safe\json_encode;

final class DefaultDataManager
{
    private AnswerTagProvider $answer_tag_provider;
    private TilesManager $tiles_manager;

    public function __construct()
    {
        $this->answer_tag_provider = new AnswerTagProvider();
        $this->tiles_manager = new TilesManager();
    }

    public function initializeDataIfNeeded(): void
    {
        if ($this->dataHasBeenInitialized()) {
            return;
        }

        $this->initializeData();
    }

    public function initializeData(): void
    {
        $incident_form = $this->createIncidentForm();
        $request_form = $this->createRequestForm();

        $root_entity = Entity::getById(0);

        $this->tiles_manager->addTile($root_entity, GlpiPageTile::class, [
            'title'        => __("Browse help articles"),
            'description'  => __("See all available help articles and our FAQ."),
            'illustration' => "browse-kb",
            'page'         => GlpiPageTile::PAGE_FAQ,
        ]);

        $this->tiles_manager->addTile($root_entity, FormTile::class, [
            'forms_forms_id' => $incident_form->getID(),
        ]);

        $this->tiles_manager->addTile($root_entity, FormTile::class, [
            'forms_forms_id' => $request_form->getID(),
        ]);

        $this->tiles_manager->addTile($root_entity, GlpiPageTile::class, [
            'title'        => __("Create a ticket"),
            'description'  => __("Go to our service catalog and pick a form to create a new ticket."),
            'illustration' => "request-support",
            'page'         => GlpiPageTile::PAGE_SERVICE_CATALOG,
        ]);

        $this->tiles_manager->addTile($root_entity, GlpiPageTile::class, [
            'title'        => __("See your tickets"),
            'description'  => __("View all the tickets that you have created."),
            'illustration' => "tickets",
            'page'         => GlpiPageTile::PAGE_ALL_TICKETS,
        ]);

        $this->tiles_manager->addTile($root_entity, GlpiPageTile::class, [
            'title'        => __("Make a reservation"),
            'description'  => __("Pick an available asset and reserve it for a given date."),
            'illustration' => "reservation",
            'page'         => GlpiPageTile::PAGE_RESERVATION,
        ]);
    }

    private function dataHasBeenInitialized(): bool
    {
        return countElementsInTable(Form::getTable()) > 0;
    }

    private function createIncidentForm(): Form
    {
        // Create form
        $form = $this->createForm(
            name: __('Report an issue'),
            description: __("Ask for support from our helpdesk team."),
            illustration: 'report-issue',
        );

        // Get first section
        $sections = $form->getSections();
        $section = array_pop($sections);

        // Add questions
        $this->addQuestion($section, $this->getUrgencyQuestionData());
        $this->addQuestion($section, $this->getCategoryQuestionData());
        $this->addQuestion($section, $this->getUserDevicesQuestionData());
        $this->addQuestion($section, $this->getObserversQuestionData());
        $this->addQuestion($section, $this->getLocationQuestionData());
        $title_question = $this->addQuestion($section, $this->getTitleQuestionData());
        $description_question = $this->addQuestion($section, $this->getDescriptionQuestionData());

        // Find title and description question tags, needed to configure the created ticket
        $title_tag = $this->answer_tag_provider->getTagForQuestion(
            $title_question
        );
        $description_tag = $this->answer_tag_provider->getTagForQuestion(
            $description_question
        );

        // Prepare ticket destination config
        $config = [
            // Set title using an answer
            TitleField::getKey() => (new SimpleValueConfig(
                $title_tag->html,
            ))->jsonSerialize(),

            // Set manual description using an answer
            ContentField::getAutoConfigKey() => false,
            ContentField::getKey() => (new SimpleValueConfig(
                $description_tag->html,
            ))->jsonSerialize(),

            // Force incident type
            RequestTypeField::getKey() => (new RequestTypeFieldConfig(
                strategy: RequestTypeFieldStrategy::SPECIFIC_VALUE,
                specific_request_type: Ticket::INCIDENT_TYPE,
            ))->jsonSerialize(),

            // Set last valid answer as observer
            ObserverField::getKey() => (new ObserverFieldConfig(
                strategies: [ITILActorFieldStrategy::LAST_VALID_ANSWER],
            ))->jsonSerialize(),
        ];

        // Add ticket destination
        $this->setDefaultDestinationConfig($form, $config);

        return $form;
    }

    private function createRequestForm(): Form
    {
        $form = $this->createForm(
            name: __('Request a service'),
            description: __("Ask for a service to be provided by our team."),
            illustration: 'request-service',
        );

        // Get first section
        $sections = $form->getSections();
        $section = array_pop($sections);

        // Add questions
        $this->addQuestion($section, $this->getUrgencyQuestionData());
        $this->addQuestion($section, $this->getCategoryQuestionData());
        $this->addQuestion($section, $this->getUserDevicesQuestionData());
        $this->addQuestion($section, $this->getObserversQuestionData());
        $this->addQuestion($section, $this->getLocationQuestionData());
        $title_question = $this->addQuestion($section, $this->getTitleQuestionData());
        $description_question = $this->addQuestion($section, $this->getDescriptionQuestionData());

        // Find title and description question tags, needed to configure the created ticket
        $title_tag = $this->answer_tag_provider->getTagForQuestion(
            $title_question
        );
        $description_tag = $this->answer_tag_provider->getTagForQuestion(
            $description_question
        );

        // Prepare ticket destination config
        $config = [
            // Set title using an answer
            TitleField::getKey() => (new SimpleValueConfig(
                $title_tag->html,
            ))->jsonSerialize(),

            // Set manual description using an answer
            ContentField::getAutoConfigKey() => false,
            ContentField::getKey() => (new SimpleValueConfig(
                $description_tag->html,
            ))->jsonSerialize(),

            // Force incident type
            RequestTypeField::getKey() => (new RequestTypeFieldConfig(
                strategy: RequestTypeFieldStrategy::SPECIFIC_VALUE,
                specific_request_type: Ticket::DEMAND_TYPE,
            ))->jsonSerialize(),

            // Set last valid answer as observer
            ObserverField::getKey() => (new ObserverFieldConfig(
                strategies: [ITILActorFieldStrategy::LAST_VALID_ANSWER],
            ))->jsonSerialize(),
        ];

        // Add ticket destination
        $this->setDefaultDestinationConfig($form, $config);

        return $form;
    }

    private function createForm(
        string $name,
        string $description,
        string $illustration,
    ): Form {
        $form = new Form();
        $form_id = $form->add([
            // Specific properties
            'name'         => $name,
            'description'  => $description,
            'illustration' => $illustration,
            // Common properties
            'is_active'    => true,
            'is_recursive' => true,
            'entities_id'  => 0,
        ]);

        $form = Form::getById($form_id);
        if (!$form_id || !$form instanceof Form) {
            throw new RuntimeException("Failed to create form");
        }

        return $form;
    }

    private function addQuestion(
        Section $section,
        array $question_data,
    ): Question {
        // Refresh data
        $section->getFromDB($section->getID());

        // Set common values
        $question_data['vertical_rank'] = count($section->getQuestions());
        $question_data[Section::getForeignKeyField()] = $section->getID();

        // Create question
        $question = new Question();
        if (!$question->add($question_data)) {
            throw new RuntimeException(
                "Failed to create question: " . json_encode($question_data)
            );
        }

        return $question;
    }

    private function getTitleQuestionData(): array
    {
        return [
            'type' => QuestionTypeShortText::class,
            'name' => __("Title"),
        ];
    }

    private function getDescriptionQuestionData(): array
    {
        return [
            'type' => QuestionTypeLongText::class,
            'name' => __("Description"),
            'is_mandatory' => true,
        ];
    }

    private function getCategoryQuestionData(): array
    {
        return [
            'type' => QuestionTypeItemDropdown::class,
            'name' => _n('Category', 'Categories', 1),
            'default_value' => null,
            'extra_data' => json_encode([
                'itemtype'          => ITILCategory::class,
                'categories_filter' => ['request', 'incident', 'change', 'problem'],
                'root_items_id'     => 0,
                'subtree_depth'     => 0,
            ]),
        ];
    }

    private function getUserDevicesQuestionData(): array
    {
        return [
            'type' => QuestionTypeUserDevice::class,
            'name' => __("User devices"),
            'default_value' => 0,
            'extra_data' => json_encode(['is_multiple_devices' => false]),
        ];
    }

    private function getLocationQuestionData(): array
    {
        return [
            'type' => QuestionTypeItemDropdown::class,
            'name' => _n('Location', 'Locations', 1),
            'default_value' => null,
            'extra_data' => json_encode([
                'itemtype'          => Location::class,
                'categories_filter' => [],
                'root_items_id'     => 0,
                'subtree_depth'     => 0,
            ]),
        ];
    }

    private function getUrgencyQuestionData(): array
    {
        return [
            'type' => QuestionTypeUrgency::class,
            'name' => __("Urgency"),
        ];
    }

    private function getObserversQuestionData(): array
    {
        return [
            'type' => QuestionTypeObserver::class,
            'name' => _n('Observer', 'Observers', Session::getPluralNumber()),
            'extra_data' => json_encode(['is_multiple_actors' => true]),
        ];
    }

    private function setDefaultDestinationConfig(Form $form, array $config): void
    {
        $destination = current($form->getDestinations());
        $success = $destination->update([
            'id' => $destination->getID(),
            'config'   => $config,
        ]);

        if (!$success) {
            throw new RuntimeException("Failed configure destination");
        }
    }
}
