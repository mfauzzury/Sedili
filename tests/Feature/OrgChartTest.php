<?php

namespace Tests\Feature;

use App\Filament\Pages\OrgChart;
use App\Models\Department;
use App\Services\OrgChart as OrgChartData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesEmployees;
use Tests\TestCase;

class OrgChartTest extends TestCase
{
    use CreatesEmployees, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAdminPanel();
    }

    /** @return array{tree: list<array<string, mixed>>, employees: int, departments: int} */
    private function chart(): array
    {
        return app(OrgChartData::class)->build();
    }

    /** Every node of the chart (people and departments), found by type and name or id */
    private function find(array $nodes, string $type, string|int $key): ?array
    {
        foreach ($nodes as $node) {
            if ($node['type'] === $type && ($node['name'] === $key || $node['id'] === $key)) {
                return $node;
            }
            if ($found = $this->find($node['children'], $type, $key)) {
                return $found;
            }
        }

        return null;
    }

    private function ids(array $nodes): array
    {
        return array_column($nodes, 'id');
    }

    public function test_people_sit_under_their_supervisor_and_their_department_manager(): void
    {
        $dept = Department::create(['name' => 'Engineering', 'code' => 'ENG']);
        $lead = $this->makeEmployee('Lead', attributes: ['department_id' => $dept->id]);
        $dev = $this->makeEmployee('Dev', attributes: ['department_id' => $dept->id, 'supervisor_id' => $lead->id]);
        $intern = $this->makeEmployee('Intern', attributes: ['department_id' => $dept->id]);
        $dept->forceFill(['manager_id' => $lead->id])->save();

        $engineering = $this->find($this->chart()['tree'], 'department', 'Engineering');

        $this->assertSame(3, $engineering['count']);
        // The manager heads the department; the intern has no supervisor so reports to the manager too
        $this->assertSame([$lead->id], $this->ids($engineering['children']));
        $this->assertEqualsCanonicalizing([$dev->id, $intern->id], $this->ids($engineering['children'][0]['children']));
    }

    public function test_a_department_hangs_under_the_executive_its_head_reports_to(): void
    {
        $management = Department::create(['name' => 'Management']);
        $engineering = Department::create(['name' => 'Engineering']);
        $ceo = $this->makeEmployee('Ceo', attributes: ['department_id' => $management->id]);
        $cto = $this->makeEmployee('Cto', attributes: ['department_id' => $management->id, 'supervisor_id' => $ceo->id]);
        $head = $this->makeEmployee('Head', attributes: ['department_id' => $engineering->id, 'supervisor_id' => $cto->id]);
        $engineering->forceFill(['manager_id' => $head->id])->save();

        $chart = $this->chart();

        // Management is the only department at the top, the CEO heads it, the CTO reports to the CEO ...
        $this->assertSame(['Management'], array_column($chart['tree'], 'name'));
        $this->assertSame([$ceo->id], $this->ids($chart['tree'][0]['children']));
        $this->assertSame([$cto->id], $this->ids($chart['tree'][0]['children'][0]['children']));
        // ... and Engineering hangs under the CTO, with its head right below it
        $under = $this->find($chart['tree'], 'person', $cto->id)['children'];
        $this->assertSame(['Engineering'], array_column($under, 'name'));
        $this->assertSame([$head->id], $this->ids($under[0]['children']));
        $this->assertSame(3, $chart['employees']);
        $this->assertSame(2, $chart['departments']);
    }

    public function test_admins_and_inactive_employees_are_not_in_the_chart(): void
    {
        $dept = Department::create(['name' => 'Engineering']);
        $this->makeEmployee('Active', attributes: ['department_id' => $dept->id]);
        $this->makeEmployee('Gone', attributes: ['department_id' => $dept->id, 'is_active' => false]);
        $this->makeEmployee('Boss', 'admin', ['department_id' => $dept->id]);

        $chart = $this->chart();
        $engineering = $this->find($chart['tree'], 'department', 'Engineering');

        $this->assertSame(1, $engineering['count']);
        $this->assertSame(1, $chart['employees']);
        $this->assertSame('Active Test', $engineering['children'][0]['name']);
    }

    public function test_a_reporting_loop_does_not_make_anyone_disappear(): void
    {
        $dept = Department::create(['name' => 'Engineering']);
        $a = $this->makeEmployee('A', attributes: ['department_id' => $dept->id]);
        $b = $this->makeEmployee('B', attributes: ['department_id' => $dept->id, 'supervisor_id' => $a->id]);
        $a->forceFill(['supervisor_id' => $b->id])->save();

        $chart = $this->chart();

        $this->assertNotNull($this->find($chart['tree'], 'person', $a->id));
        $this->assertNotNull($this->find($chart['tree'], 'person', $b->id));
    }

    public function test_two_departments_that_report_to_each_other_stay_in_the_chart(): void
    {
        $one = Department::create(['name' => 'One']);
        $two = Department::create(['name' => 'Two']);
        $a = $this->makeEmployee('A', attributes: ['department_id' => $one->id]);
        $b = $this->makeEmployee('B', attributes: ['department_id' => $two->id, 'supervisor_id' => $a->id]);
        $a->forceFill(['supervisor_id' => $b->id])->save();
        $one->forceFill(['manager_id' => $a->id])->save();
        $two->forceFill(['manager_id' => $b->id])->save();

        $chart = $this->chart();

        $this->assertNotNull($this->find($chart['tree'], 'department', 'One'));
        $this->assertNotNull($this->find($chart['tree'], 'department', 'Two'));
        $this->assertNotNull($this->find($chart['tree'], 'person', $a->id));
        $this->assertNotNull($this->find($chart['tree'], 'person', $b->id));
    }

    public function test_people_without_a_department_sit_at_the_top_and_are_not_a_department(): void
    {
        $dept = Department::create(['name' => 'Engineering']);
        $ceo = $this->makeEmployee('Ceo');
        $cto = $this->makeEmployee('Cto', attributes: ['supervisor_id' => $ceo->id]);
        $head = $this->makeEmployee('Head', attributes: ['department_id' => $dept->id, 'supervisor_id' => $cto->id]);
        $dept->forceFill(['manager_id' => $head->id])->save();

        $chart = $this->chart();

        // The CEO is at the top, with no box around them; the CTO reports to the CEO; Engineering hangs under the CTO
        $this->assertSame([['person', $ceo->id]], array_map(fn($n) => [$n['type'], $n['id']], $chart['tree']));
        $this->assertSame([$cto->id], $this->ids($chart['tree'][0]['children']));
        $this->assertSame(['Engineering'], array_column($chart['tree'][0]['children'][0]['children'], 'name'));
        $this->assertSame(3, $chart['employees']);
        $this->assertSame(1, $chart['departments']);
    }

    public function test_the_page_shows_the_chart_to_an_admin_and_is_closed_to_employees(): void
    {
        $dept = Department::create(['name' => 'Engineering']);
        $this->makeEmployee('Visible', attributes: ['department_id' => $dept->id]);
        $this->loginAsAdmin();

        // Top-level departments are open; the people inside are in the page for the expand / collapse
        Livewire::test(OrgChart::class)->assertSee('Engineering')->assertSee('Visible Test')->assertSee('Expand all');
        $this->get(OrgChart::getUrl())->assertOk();

        $this->actingAs($this->makeEmployee('Regular'));
        $this->get(OrgChart::getUrl())->assertForbidden();
    }
}
