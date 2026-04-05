<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserBulkSeeder extends Seeder
{
    public function run(): void
    {
        $organizationId = 4;
        $projectId = 6;

        $users = [
            ['full_name' => 'BUDI YANTO', 'jabatan' => 'ANGGOTA BARU PDL', 'email' => 'BUDICANIAGGO1985@GMAIL.COM', 'phone' => '081354012469'],
            ['full_name' => 'NURDIN DURI', 'jabatan' => 'ANGGOTA', 'email' => 'NURDINDURI09@GMAIL.COM', 'phone' => '082247586432'],
            ['full_name' => 'RADITYO FIKRI NUGROHO', 'jabatan' => 'KOMANDAN REGU', 'email' => 'NUGROHORADITIOFIKRINUGROHO@GMAIL.COM', 'phone' => '087881837080'],
            ['full_name' => 'SARDA', 'jabatan' => 'ANGGOTA LAMA FULL', 'email' => 'SARDASYAHPUTRA12@GMAIL.COM', 'phone' => '08980488164'],
            ['full_name' => 'TAOFIQ HIDAYAT', 'jabatan' => 'ANGGOTA LAMA FULL', 'email' => 'FIKHASHEZA@GMAIL.COM', 'phone' => '085719308955'],
            ['full_name' => 'WAL ASRI', 'jabatan' => 'ANGGOTA LAMA FULL', 'email' => 'walasri949@gmail.com', 'phone' => '085213497931'],
            ['full_name' => 'YUSIRMAN', 'jabatan' => 'ANGGOTA BARU PDL', 'email' => 'YUSIRMANIMAN@GMAIL.COM', 'phone' => '081398102216'],
            ['full_name' => 'BAGUS SETIAWAN', 'jabatan' => 'ANGGOTA', 'email' => 'SETIAWANBAGUS47@GMAIL.COM', 'phone' => '081292148703'],
            ['full_name' => 'BENYAMIN GOA', 'jabatan' => 'ANGGOTA BARU PDL', 'email' => 'RAFLYNLAWU@GMAIL.COM', 'phone' => '081219762625'],
            ['full_name' => 'MUTALIP AINSA', 'jabatan' => 'ANGGOTA', 'email' => 'IPULAINSA@GMAIL.COM', 'phone' => '081354012469'],
            ['full_name' => 'KRISMA HIDAYAT', 'jabatan' => 'ANGGOTA BARU PDL', 'email' => 'UDAMAULANA1999@GMAIL.COM', 'phone' => '083816209785'],
            ['full_name' => 'DANI PERMANA YUSUP', 'jabatan' => 'ANGGOTA BARU PDH', 'email' => 'jilongmangu@gmail.com', 'phone' => '088980681921'],
            ['full_name' => 'ZAINAL ABIDIN', 'jabatan' => 'ANGGOTA BARU PDL', 'email' => 'ZAINALJENGKOL493@GMAIL.COM', 'phone' => '085282079273'],
            ['full_name' => 'AGUSTINUS GEA', 'jabatan' => 'ANGGOTA BARU PDH', 'email' => 'AGUSGEA124@GMAIL.COM', 'phone' => '081282697018'],
            ['full_name' => 'SYARIP MULYONO', 'jabatan' => 'ANGGOTA BARU PDL', 'email' => 'SYARIPMULYONO386@GMAIL.COM', 'phone' => '085960190102'],
            ['full_name' => 'ARIA SEFTAMA', 'jabatan' => 'ANGGOTA BARU PDL', 'email' => 'rxk9643@mail.com', 'phone' => '082278859199'],
            ['full_name' => 'ELI RUSWANTO', 'jabatan' => 'ANGGOTA BARU PDL', 'email' => 'eliruswanto497@gmail.com', 'phone' => '081383735799'],
            ['full_name' => 'ADITYA PRATAMA', 'jabatan' => 'ANGGOTA BARU PDL', 'email' => 'adityaprataaditya30@gmail.com', 'phone' => '083844605820'],
            ['full_name' => 'RENDY SEPRIYAN', 'jabatan' => '', 'email' => 'Rendysepriyan1@gmail.com', 'phone' => '08567436023'],
            ['full_name' => 'ANGGA KUSUMA SAPUTRA', 'jabatan' => '', 'email' => 'anggajaxz@gmail.com', 'phone' => '082213530821'],
            ['full_name' => 'RAHMAT HIDAYAT', 'jabatan' => 'KOMANDAN REGU', 'email' => 'RAHMATVANA15@GMAIL.COM', 'phone' => '087892765908'],
            ['full_name' => 'ARYANTO ABUBEKAR', 'jabatan' => 'ANGGOTA', 'email' => 'ABEBRINGS@GMAIL.COM', 'phone' => '081316884505'],
            ['full_name' => 'BAYU EKA MARIO', 'jabatan' => 'ANGGOTA', 'email' => 'NIKOTIN.CAFEIN@GMAIL.COM', 'phone' => '089530528428'],
            ['full_name' => 'MUHAMMAD RAYHAN', 'jabatan' => 'ANGGOTA', 'email' => 'XRAYHARDX@GMAIL.COM', 'phone' => '085810375505'],
            ['full_name' => 'MUHAMMAD MUSTOFA', 'jabatan' => 'ANGGOTA', 'email' => 'MUHAMMADMUSTOFFAA181118@GMAIL.COM', 'phone' => '089510507355'],
            ['full_name' => 'OKTAFIANUS DAE', 'jabatan' => 'ANGGOTA', 'email' => 'OCTOFIANUS220800@GMAIL.COM', 'phone' => '082144734016'],
            ['full_name' => 'RUSDI ODING', 'jabatan' => 'ANGGOTA', 'email' => 'RUSDIODING994@GMAIL.COM', 'phone' => '083815014086'],
            ['full_name' => 'ANDRI FAISAL', 'jabatan' => 'ANGGOTA', 'email' => 'ARINISAJA46@GMAIL.COM', 'phone' => '083825327386'],
            ['full_name' => 'FIQRI AGUSTI', 'jabatan' => 'ANGGOTA', 'email' => 'FIQRIAGUSTI01@GMAIL.COM', 'phone' => '085939717149'],
            ['full_name' => 'MUHAMAD RAFLI', 'jabatan' => 'ANGGOTA', 'email' => 'MUHAMMADRAFLY0235@GMAIL.COM', 'phone' => '085710554986'],
            ['full_name' => 'DAVID HAMONANGAN SIMANGUNSONG', 'jabatan' => 'ANGGOTA', 'email' => 'DAVIDLAMPUNG116@GMAIL.COM', 'phone' => '081397389315'],
            ['full_name' => 'MUSTAFA GABA', 'jabatan' => 'ANGGOTA', 'email' => 'GABAMUSTAFA183@GMAIL.COM', 'phone' => '081339736196'],
            ['full_name' => 'DZULFAKIR AMRULLAH', 'jabatan' => 'ANGGOTA', 'email' => 'DZULFAKIRAMRULLAH@GMAIL.COM', 'phone' => '085210371568'],
            ['full_name' => 'SIMEON LEU', 'jabatan' => 'ANGGOTA', 'email' => 'SIMEONLEU22@GMAIL.COM', 'phone' => '082254712365'],
            ['full_name' => 'MUHAMAD HASIM NING', 'jabatan' => 'ANGGOTA', 'email' => 'HASIMNING6@GMAIL.COM', 'phone' => '081219034224'],
            ['full_name' => 'SHYAHRUL HIDAYAT', 'jabatan' => 'ANGGOTA', 'email' => 'SAHRULHIDAYAT05@GMAIL.COM', 'phone' => '087880688405'],
            ['full_name' => 'RAIHAN HIDAYAT', 'jabatan' => 'ANGGOTA', 'email' => 'HIDAYATRAIHAN44@GMAIL.COM', 'phone' => '088708609495'],
            ['full_name' => 'ALVY MARCELLINO ADE IRAWAN', 'jabatan' => 'ANGGOTA', 'email' => 'OO347138.@GMAIL.COM', 'phone' => '081336351511'],
            ['full_name' => 'WAHYU TRI WIDODO', 'jabatan' => 'ANGGOTA', 'email' => 'AGAMPRADIPTAPRATAMA08@GMAIL.COM', 'phone' => '081213445007'],
            ['full_name' => 'SUHERMIN', 'jabatan' => 'ANGGOTA', 'email' => 'HERMINSU01@GMAIL.COM', 'phone' => '082112444200'],
            ['full_name' => 'CECEP DERMAWAN RESISTEN', 'jabatan' => 'ANGGOTA', 'email' => 'CECEPDERMAWAN139@GMAIL.COM', 'phone' => '08811075937'],
            ['full_name' => 'MAIN TUSLA', 'jabatan' => 'ANGGOTA', 'email' => 'MAINTUSLA923@GMAIL.COM', 'phone' => '081315932163'],
            ['full_name' => 'ODI SUKARDI', 'jabatan' => 'ANGGOTA LAMA FULL', 'email' => 'DITH.ESKAERDE@GMAIL.COM', 'phone' => '089513419910'],
            ['full_name' => 'RIAN RONALDO', 'jabatan' => 'ANGGOTA BARU PDL', 'email' => 'RIANRONALDO2001@GMAIL.COM', 'phone' => '083170626934'],
            ['full_name' => 'SAHRIYADI', 'jabatan' => 'ANGGOTA LAMA FULL', 'email' => 'YADISAHRI22@GMAIL.COM', 'phone' => '082312022043'],
            ['full_name' => 'SARWEDI', 'jabatan' => 'KOMANDAN REGU', 'email' => 'sarwedisiregar780@gmail.com', 'phone' => '081219406193'],
            ['full_name' => 'TUNGGUL PURNOMO', 'jabatan' => 'ANGGOTA LAMA FULL', 'email' => 'PURNOMOTUNGGUL22@GMAIL.COM', 'phone' => '081219979023'],
            ['full_name' => 'AGUS IMANUDIN', 'jabatan' => 'ANGGOTA', 'email' => 'AGUSIMANUDIN003@GMAIL.COM', 'phone' => '085819722849'],
            ['full_name' => 'RAFI DARMADJI', 'jabatan' => 'ANGGOTA', 'email' => 'RDAARMAAJI@GMAIL.COM', 'phone' => '089614439065'],
            ['full_name' => 'MOHAMAD ABUBAKAR', 'jabatan' => 'ANGGOTA BARU PDL', 'email' => 'EKENMOHAMAD25@GMAIL.COM', 'phone' => '085238368524'],
            ['full_name' => 'HADI FIRDAUS', 'jabatan' => 'ANGGOTA BARU PDL', 'email' => 'ARIFSTOKGAME@GMAIL.COM', 'phone' => '085890763374'],
            ['full_name' => 'AMAMIH YAMIS', 'jabatan' => 'ANGGOTA BARU PDH', 'email' => 'AMAMIHYAMIS@GMAIL.COM', 'phone' => '087776030445'],
            ['full_name' => 'FRASTIYANI', 'jabatan' => 'ANGGOTA BARU PDL', 'email' => 'FRASTIYANI96@GMAIL.COM', 'phone' => '087862332141'],
            ['full_name' => 'ALHAPIP AHMAD AMIN', 'jabatan' => 'ANGGOTA BARU PDH', 'email' => 'ALHAPIPAHMADALHAPIP@GMAIL.COM', 'phone' => '085789049920'],
            ['full_name' => 'M ROYSUL ARIFIN', 'jabatan' => 'ANGGOTA BARU PDL', 'email' => 'royzuel161216@google.com', 'phone' => '085809663412'],
            ['full_name' => 'ILHAM KURNIA SINURAT', 'jabatan' => 'ANGGOTA BARU PDL', 'email' => 'ilhamsinurat11@gmail.com', 'phone' => '082384189246'],
            ['full_name' => 'TRI PURBO HAPSORO', 'jabatan' => 'ANGGOTA BARU PDL', 'email' => 'Tripurbohapsoro@gmail.com', 'phone' => '085215697258'],
            ['full_name' => 'MUHAMAD SURYA RAMDANI', 'jabatan' => 'ANGGOTA BARU PDL', 'email' => 'suryaramdani51@gmail.com', 'phone' => '085175451912'],
            ['full_name' => 'ACHMAD SYURKATI', 'jabatan' => 'ANGGOTA BARU PDL', 'email' => 'amatbelo81@gmail.com', 'phone' => '089604260812'],
            ['full_name' => 'IHWANSAH', 'jabatan' => 'ANGGOTA BARU PDH', 'email' => 'ikhwansyah777@gmail.com', 'phone' => '088298665722'],
            ['full_name' => 'AHMADSULAEMAN', 'jabatan' => 'ANGGOTA LAMA PDL', 'email' => 'SHAKILAAMELIA071016@GMAIL.COM', 'phone' => '082113628224'],
            ['full_name' => 'MOHAMAD EKA FADHILLAH', 'jabatan' => 'ANGGOTA LAMA PDL', 'email' => 'mfbilaliksan98@gmail.com', 'phone' => '0895403304324'],
            ['full_name' => 'NUNY SULISTIOWATI', 'jabatan' => 'SECWAN', 'email' => 'NUNYSULISTIOWATI@GMAIL.COM', 'phone' => '0895331641637'],
            ['full_name' => 'RANGGA HADI WIJAYA', 'jabatan' => 'ANGGOTA LAMA FULL', 'email' => 'RANGGAHADIWIJAYA10@GMAIL.COM', 'phone' => '088905153692'],
            ['full_name' => 'SUMARJIYONO', 'jabatan' => 'ANGGOTA LAMA FULL', 'email' => 'AJISUMARJIYONO@GMAIL.COM', 'phone' => '081317009552'],
            ['full_name' => 'TRI SEKTIYAWAN', 'jabatan' => 'ANGGOTA LAMA FULL', 'email' => 'TRISEKTIYAWAN3TRISEKTIYAWAN@GMAIL.COM', 'phone' => '087831553035'],
            ['full_name' => 'MESRA ZALUKHU', 'jabatan' => 'ANGGOTA BARU PDL', 'email' => 'MESRAAJA03@GMAIL.COM', 'phone' => '085234174027'],
            ['full_name' => 'DIKI WAHYUDI', 'jabatan' => 'ANGGOTA BARU PDL', 'email' => 'DIKI085793981133@GMAIL.COM', 'phone' => '085795400281'],
            ['full_name' => 'PRUDENSIUS RESI', 'jabatan' => 'ANGGOTA BARU PDL', 'email' => 'RESIPRUDENSIUS@GMAIL.COM', 'phone' => '081338506503'],
            ['full_name' => 'RESA LORENSA', 'jabatan' => 'ADMIN UTAMA', 'email' => 'LORENSARESA2323@GMAIL.COM', 'phone' => '085691255669'],
            ['full_name' => 'M IKHAWAN RIZKI WAHID', 'jabatan' => 'ANGGOTA BARU PDL', 'email' => 'ikhwanrizki86@gmail.com', 'phone' => '085773230824'],
            ['full_name' => 'WIWIN WINARSIH', 'jabatan' => 'SECWAN', 'email' => 'Wiwinfajrina05@gmail.com', 'phone' => '0895365537315'],
            ['full_name' => 'PURNOMO', 'jabatan' => 'ANGGOTA BARU PDH', 'email' => 'purnomobinsudewo@gmail.com', 'phone' => '083827644602'],
        ];

        foreach ($users as $item) {
            $fullName = trim(preg_replace('/\s+/', ' ', $item['full_name']));
            $username = Str::lower(preg_replace('/\s+/', '', $fullName));

            User::updateOrCreate(
                ['username' => $username],
                [
                    'organization_id' => $organizationId,
                    'project_id'      => $projectId,
                    'full_name'       => $fullName,
                    'email'           => Str::lower(trim($item['email'])),
                    'phone'           => trim($item['phone']),
                    'role'            => $this->mapRole($item['jabatan']),
                    'password'        => Hash::make($username . '123'),
                ]
            );
        }
    }

    private function mapRole(?string $jabatan): string
    {
        $jabatan = Str::upper(trim((string) $jabatan));

        return match (true) {
            str_contains($jabatan, 'KOMANDAN REGU') => 'komandan_regu',
            str_contains($jabatan, 'ADMIN UTAMA') => 'admin_project',
            str_contains($jabatan, 'SECWAN') => 'anggota',
            default => 'anggota',
        };
    }
}