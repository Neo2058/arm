<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WebRoutesSplitTest extends TestCase
{
    public function test_application_named_routes_still_exist(): void
    {
        $expected = [
            'about',
            'about.request',
            'install',
            'install.android',
            'install.ios',
            'login',
            'login.post',
            'logout',
            'mainMenu',
            'barrier.show',
            'barrier.verify',
            'device.register.form',
            'device.register.submit',
            'documents.index',
            'documents.file',
            'documents.download',
            'admin.documents.serve',
            'admin.training-materials.serve',
            'quiz.results.history',
            'quiz.show',
            'quiz.general',
            'quiz-results.store',
            'teaching.timer',
            'timer',
            'rosisi.index',
            'rosisi.sign',
            'rosisi.log-formular',
            'rosisi.statistics',
            'training.topics',
            'training.topic',
            'training.video',
            'training.audio',
            'training.video.stream',
            'training.audio.stream',
            'training.comment.store',
            'training.reaction.store',
            'naryady.index',
            'naryady.show',
            'naryady.search',
            'work.time.index',
            'podstroiki.index',
            'podstroiki.store',
            'podstroiki.update-status',
            'journal.index',
            'journal.todo.add',
            'journal.todo.complete',
            'journal.todo.update',
            'journal.todo.destroy',
            'journal.document.upload',
            'journal.ask',
            'journal.settings',
            'journal.settings.update',
            'journal.standards',
            'journal.standards.update',
            'journal.history',
            'journal.report',
            'journal.report.vacation.add',
            'journal.report.vacation.delete',
            'journal.naryad-search',
            'journal.naryad-search.run',
            'naryad.index',
            'naryad.partial.setka',
            'naryad.assign',
            'uchet.index',
            'uchet.accounts',
            'uchet.lsbuh',
            'uchet.reports',
            'bug.report.store',
            'actions.log',
            'backstage.index',
            'backstage.store',
            'backstage.support',
            'webhooks.yookassa',
        ];

        foreach ($expected as $name) {
            $this->assertTrue(Route::has($name), $name);
        }
    }

    public function test_signed_file_routes_still_require_signature(): void
    {
        $this->assertContains('signed', Route::getRoutes()->getByName('documents.file')->middleware());
        $this->assertContains('signed', Route::getRoutes()->getByName('admin.documents.serve')->middleware());
        $this->assertContains('signed', Route::getRoutes()->getByName('admin.training-materials.serve')->middleware());
        $this->assertContains('signed', Route::getRoutes()->getByName('training.video.stream')->middleware());
        $this->assertContains('signed', Route::getRoutes()->getByName('training.audio.stream')->middleware());
    }

    public function test_unnamed_legacy_uris_are_still_registered(): void
    {
        $uris = collect(Route::getRoutes())->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri());

        $this->assertTrue($uris->contains('GET|HEAD /'));
        $this->assertTrue($uris->contains('POST telegram/webhook'));
        $this->assertTrue($uris->contains('GET|HEAD api/work-shifts'));
        $this->assertTrue($uris->contains('POST api/work-shifts'));
        $this->assertTrue($uris->contains('POST api/work-shifts/preview'));
        $this->assertTrue($uris->contains('GET|HEAD api/documents/{document}/click'));
    }
}
