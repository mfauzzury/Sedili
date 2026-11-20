<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Database\Seeder;

/**
 * Sample data for the Org Chart: an IT company with a C-level (CEO, CTO, COO, CFO, in no department)
 * and four departments. Not part of DatabaseSeeder; run it on demand:
 *
 *   php artisan db:seed --class=OrgChartSampleSeeder
 *
 * It can be run again. It moves the sample employees into the four departments (their positions
 * follow), then removes the earlier sample departments once they are empty. The sample employees
 * can log in to the portal with the password "password".
 */
class OrgChartSampleSeeder extends Seeder
{
    /** Department name => [code, description, head email] */
    private const DEPARTMENTS = [
        'Admin & Finance' => ['ADMFIN', 'Administration, human resources, accounting and finance.', 'ahmad.faizal@example.com'],
        'Software Development' => ['DEV', 'Builds, tests and maintains the products.', 'farid.hakim@example.com'],
        'Innovation' => ['INNO', 'Product management, design, research and business development.', 'elaine.goh@example.com'],
        'Support' => ['SUP', 'Customer and IT support, operations and infrastructure.', 'syafiqah.zulkifli@example.com'],
    ];

    /** Earlier sample departments, removed once they have no employee left */
    private const LEGACY_DEPARTMENTS = [
        'Human Resources', 'Finance', 'Information Technology', 'Sales & Marketing', 'Operations',
        'Management', 'Engineering', 'Product & Design', 'Quality Assurance', 'DevOps',
    ];

    /**
     * email => [department (null: C-level), reports to (email), and for a new employee:
     * first name, last name, position code, position title].
     * The employees that already exist keep their name and their position.
     */
    private const PEOPLE = [
        // C-level
        'aisha.rahman@example.com' => [null, null, 'Aisha', 'binti Rahman', 'MGT-CEO', 'Chief Executive Officer'],
        'daniel.chong@example.com' => [null, 'aisha.rahman@example.com', 'Daniel', 'Chong', 'MGT-CTO', 'Chief Technology Officer'],
        'sarah.lim@example.com' => [null, 'aisha.rahman@example.com', 'Sarah', 'Lim', 'MGT-COO', 'Chief Operating Officer'],
        'henry.teoh@example.com' => [null, 'aisha.rahman@example.com', 'Henry', 'Teoh', 'MGT-CFO', 'Chief Financial Officer'],

        // Admin & Finance (reports to the CFO)
        'ahmad.faizal@example.com' => ['Admin & Finance', 'henry.teoh@example.com'],
        'nurul.aisyah@example.com' => ['Admin & Finance', 'ahmad.faizal@example.com'],
        'weiming.tan@example.com' => ['Admin & Finance', 'ahmad.faizal@example.com'],
        'siewhua.lim@example.com' => ['Admin & Finance', 'weiming.tan@example.com'],

        // Software Development (reports to the CTO)
        'farid.hakim@example.com' => ['Software Development', 'daniel.chong@example.com', 'Farid', 'Hakim', 'ENG-MGR', 'Engineering Manager'],
        'meiling.chan@example.com' => ['Software Development', 'farid.hakim@example.com', 'Mei Ling', 'Chan', 'ENG-LEAD-BE', 'Backend Tech Lead'],
        'arjun.nair@example.com' => ['Software Development', 'farid.hakim@example.com', 'Arjun', 'Nair', 'ENG-LEAD-FE', 'Frontend Tech Lead'],
        'nadia.hassan@example.com' => ['Software Development', 'farid.hakim@example.com', 'Nadia', 'Hassan', 'QA-LEAD', 'QA Lead'],
        'huimin.ong@example.com' => ['Software Development', 'meiling.chan@example.com', 'Hui Min', 'Ong', 'ENG-BE', 'Backend Developer'],
        'zulkifli.ahmad@example.com' => ['Software Development', 'meiling.chan@example.com', 'Zulkifli', 'Ahmad', 'ENG-BE', 'Backend Developer'],
        'rajesh.kumar@example.com' => ['Software Development', 'meiling.chan@example.com'],
        'priya.subramaniam@example.com' => ['Software Development', 'arjun.nair@example.com', 'Priya', 'Subramaniam', 'ENG-FE', 'Frontend Developer'],
        'amir.hamzah@example.com' => ['Software Development', 'arjun.nair@example.com', 'Amir', 'Hamzah', 'ENG-FE', 'Frontend Developer'],
        'jason.lee@example.com' => ['Software Development', 'nadia.hassan@example.com', 'Jason', 'Lee', 'QA-ENG', 'QA Engineer'],
        'farah.nabila@example.com' => ['Software Development', 'nadia.hassan@example.com', 'Farah', 'Nabila', 'QA-ENG', 'QA Engineer'],

        // Innovation (reports to the CEO)
        'elaine.goh@example.com' => ['Innovation', 'aisha.rahman@example.com', 'Elaine', 'Goh', 'PROD-MGR', 'Product Manager'],
        'ryan.tan@example.com' => ['Innovation', 'elaine.goh@example.com', 'Ryan', 'Tan', 'PROD-UX', 'UI/UX Designer'],
        'sofia.ismail@example.com' => ['Innovation', 'elaine.goh@example.com', 'Sofia', 'Ismail', 'PROD-BA', 'Business Analyst'],
        'kahyee.wong@example.com' => ['Innovation', 'elaine.goh@example.com'],
        'hafiz.yusof@example.com' => ['Innovation', 'kahyee.wong@example.com'],

        // Support (reports to the COO)
        'syafiqah.zulkifli@example.com' => ['Support', 'sarah.lim@example.com'],
        'danial.ismail@example.com' => ['Support', 'syafiqah.zulkifli@example.com'],
        'kavitha.muniandy@example.com' => ['Support', 'syafiqah.zulkifli@example.com'],
        'kumar.pillai@example.com' => ['Support', 'syafiqah.zulkifli@example.com', 'Kumar', 'Pillai', 'DEVOPS-LEAD', 'DevOps Lead'],
        'sitihajar.omar@example.com' => ['Support', 'kumar.pillai@example.com', 'Siti Hajar', 'binti Omar', 'DEVOPS-ENG', 'DevOps Engineer'],
    ];

    public function run(): void
    {
        $departments = [];
        foreach (self::DEPARTMENTS as $name => [$code, $description]) {
            $departments[$name] = Department::updateOrCreate(['name' => $name], ['code' => $code, 'description' => $description]);
        }

        $n = Employee::withTrashed()->count();
        $people = [];
        foreach (self::PEOPLE as $email => $row) {
            [$departmentName] = $row;
            $departmentId = $departmentName ? $departments[$departmentName]->id : null;

            $employee = Employee::withTrashed()->firstWhere('email', $email);
            if ($employee === null) {
                [, , $first, $last, $positionCode, $positionTitle] = $row;
                $position = Position::updateOrCreate(['code' => $positionCode], ['title' => $positionTitle, 'department_id' => $departmentId]);
                $n++;
                $employee = (new Employee)->forceFill([
                    'employee_code' => sprintf('EMP-%03d', $n),
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => $email,
                    'password' => 'password',
                    'phone' => sprintf('+60 11-%04d %04d', 1000 + $n, 2000 + $n),
                    'national_id' => sprintf('90%04d-10-%04d', 1000 + $n, 5000 + $n),
                    'employment_type' => 'Permanent',
                    'hire_date' => now()->subMonths(6 + $n)->toDateString(),
                    'next_of_kin_name' => 'Family of ' . $first,
                    'next_of_kin_relationship' => 'Sibling',
                    'next_of_kin_phone' => sprintf('+60 12-%04d %04d', 3000 + $n, 4000 + $n),
                    'position_id' => $position->id,
                ]);
            }
            $employee->forceFill(['department_id' => $departmentId, 'is_active' => true])->save();
            $employee->syncRoles(['employee']);

            // The position follows the employee, so that it survives the removal of the old department
            if ($employee->position_id) {
                Position::whereKey($employee->position_id)->update(['department_id' => $departmentId]);
            }
            $people[$email] = $employee;
        }

        // Reporting lines, once everybody exists
        foreach (self::PEOPLE as $email => $row) {
            $people[$email]->forceFill(['supervisor_id' => $row[1] ? $people[$row[1]]->id : null])->save();
        }

        // Department heads (no "you have been assigned a department" notification for sample data)
        Department::withoutEvents(function () use ($departments, $people) {
            foreach (self::DEPARTMENTS as $name => [, , $headEmail]) {
                $departments[$name]->forceFill(['manager_id' => $people[$headEmail]->id])->save();
            }
        });

        // The earlier sample departments, once empty (deleting a department deletes its positions,
        // which is why the positions were moved first)
        foreach (Department::whereIn('name', self::LEGACY_DEPARTMENTS)->whereDoesntHave('employees', fn($query) => $query->withTrashed())->get() as $legacy) {
            $legacy->delete();
        }
    }
}
