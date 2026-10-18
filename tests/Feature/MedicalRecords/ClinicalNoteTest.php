<?php

namespace Tests\Feature\MedicalRecords;

use App\Modules\Consultations\Models\Consultation;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\MedicalRecords\Models\ClinicalNoteVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\Support\ConsultationFixtures;
use Tests\TestCase;

class ClinicalNoteTest extends TestCase
{
    use ConsultationFixtures;

    private Consultation $consultation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->consultation = $this->activeConsultation();
        Sanctum::actingAs($this->consultation->doctor->user, ['*']);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/consultations/{$this->consultation->id}/clinical-note{$suffix}";
    }

    private function draftAndFinalize(): void
    {
        $this->putJson($this->url(), [
            'subjective' => 'Itchy rash for two weeks', 'assessment' => 'Contact dermatitis',
            'plan' => 'Topical steroid, avoid irritant', 'follow_up' => 'Review in 2 weeks',
        ])->assertCreated(); // first save creates the note
        $this->postJson($this->url('/finalize'))->assertOk();
    }

    public function test_notes_cannot_be_written_before_the_consultation_starts(): void
    {
        $scheduled = $this->scheduledConsultation();
        Sanctum::actingAs($scheduled->doctor->user, ['*']);

        $this->assertErrorCode($this->putJson("/api/v1/consultations/{$scheduled->id}/clinical-note", ['assessment' => 'x']), 409, 'consultation_not_started');
    }

    public function test_draft_is_private_to_the_author_until_finalised(): void
    {
        $this->putJson($this->url(), ['subjective' => 'Initial notes'])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.content.subjective', 'Initial notes');
        $this->putJson($this->url(), ['subjective' => 'Initial notes, revised'])->assertOk()
            ->assertJsonPath('data.content.subjective', 'Initial notes, revised');

        $this->assertStringNotContainsString('Initial', (string) DB::table('clinical_notes')->value('draft_content'));

        Sanctum::actingAs($this->consultation->patient->user, ['*']);
        $this->assertErrorCode($this->getJson($this->url()), 403, 'forbidden');
    }

    public function test_finalising_requires_assessment_and_plan(): void
    {
        $this->putJson($this->url(), ['subjective' => 'Only history'])->assertCreated();

        $this->assertErrorCode($this->postJson($this->url('/finalize')), 422, 'note_incomplete');
    }

    public function test_finalised_note_is_visible_to_the_patient_and_records_author_and_time(): void
    {
        $this->draftAndFinalize();

        Sanctum::actingAs($this->consultation->patient->user, ['*']);
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.status', 'final')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.content.assessment', 'Contact dermatitis')
            ->assertJsonPath('data.author.doctor_id', $this->consultation->doctor_id);

        $this->assertNotNull(ClinicalNoteVersion::firstOrFail()->created_at);
        $this->assertSame($this->consultation->doctor->user_id, ClinicalNoteVersion::firstOrFail()->author_user_id);
    }

    public function test_final_notes_cannot_be_silently_overwritten(): void
    {
        $this->draftAndFinalize();

        $this->assertErrorCode($this->putJson($this->url(), ['assessment' => 'Changed']), 409, 'note_finalized');
    }

    public function test_amendments_create_attributed_versions_and_preserve_history(): void
    {
        $this->draftAndFinalize();

        $this->postJson($this->url('/amend'), ['plan' => 'Oral antihistamine added'])->assertStatus(422); // reason required

        $this->postJson($this->url('/amend'), ['plan' => 'Topical steroid and oral antihistamine', 'reason' => 'Added antihistamine after review'])
            ->assertOk()
            ->assertJsonPath('data.status', 'amended')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.content.plan', 'Topical steroid and oral antihistamine')
            ->assertJsonPath('data.content.assessment', 'Contact dermatitis');

        $versions = $this->getJson($this->url('/versions'))->assertOk()->json('data');
        $this->assertSame([1, 2], array_column($versions, 'version'));
        $this->assertSame('Topical steroid, avoid irritant', $versions[0]['content']['plan']);
        $this->assertSame('Added antihistamine after review', $versions[1]['change_reason']);
    }

    public function test_versions_are_immutable_in_the_application_and_database(): void
    {
        $this->draftAndFinalize();
        $version = ClinicalNoteVersion::firstOrFail();

        try {
            $version->update(['change_reason' => 'tamper']);
            $this->fail('Eloquent update should be rejected');
        } catch (LogicException) {
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');
        DB::transaction(fn () => DB::table('clinical_note_versions')->where('id', $version->id)->update(['change_reason' => 'tamper']));
    }

    public function test_only_the_treating_doctor_can_document(): void
    {
        Sanctum::actingAs(Doctor::factory()->approved()->create()->user, ['*']);

        $this->assertErrorCode($this->putJson($this->url(), ['assessment' => 'x']), 403, 'forbidden');
    }
}
