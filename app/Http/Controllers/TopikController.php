<?php

namespace App\Http\Controllers;

use App\Mail\EmailMahasiswaTerpilih;
use Illuminate\Support\Facades\Mail;
use App\Models\Topik;
use App\Models\TopikSkripsi;
use App\Models\AmbilTopikTugasAkhir;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\DB;


class TopikController extends Controller
{
    public $curr = 'CURRENT_TIMESTAMP';

    public function __construct(TopikSkripsi $model)
    {
        $this->model = $model;
    }

    /**
     * Menampilkan seluruh topik skripsi
     */
    public function allTopikSkripsi()
    {
        $nipy = Session::get('nipy');
        return view('all-topik', ['allTopikSkripsi' => $this->model->getAllTopikSkripsi()]);
    }

    # Menampilkan seluruh topik skripsi buat mahasiswa
    public function allTopikSkripsiMahasiswa()
    {
        $nim = Session::get('nim');

        if (empty($this->model->getMahasiswaAmbilTopikSkripsi($nim))) {
            $ambilTopikSkripsi['idTopikSkripsi'] = 0;
            foreach ($this->model->getAllTopikSkripsi() as $value) {
                if ($value->sisaBlockingDay) {
                    $ambilTopikSkripsi['sisaBlockingDay'] = $value->sisaBlockingDay;
                }
            }
        }
        if (!empty($this->model->getMahasiswaAmbilTopikSkripsi($nim))) {
            foreach ($this->model->getMahasiswaAmbilTopikSkripsi($nim) as $value) {
                $ambilTopikSkripsi['idTopikSkripsi'] = $value->idTopikSkripsi;
                $ambilTopikSkripsi['sisaBlockingDay'] = $value->sisaBlockingDay;
            }
        }
        //dd($this->model->getAllTopikSkripsi());
        return view('mahasiswa/all-topik', [
            'allTopikSkripsiMahasiswa' => $this->model->getAllTopikSkripsi(),
            'isAmbil' => $ambilTopikSkripsi
        ]);
    }

    # Menampilkan detail topik skripsi tertentu
    public function detailTopikSkripsiByID($id, $dosenMahasiswa)
    {
        $nim = Session::get('nim');

        if ($dosenMahasiswa == "dosen") {
            return view('details_skripsi', [
                'detailsTopikSkripsi' => $this->model->getDetailTopikSkripsiByID($id),
                'listMahasiswa' => $this->model->getAllMahasiswaMendaftarTopikSkripsiByID($id),
                'allTopikSkripsi' => $this->model->getAllTopikSkripsi()
            ]);
        }
        if ($dosenMahasiswa == "mahasiswa") {
            //dd($this->model->ruleAmbilTopik($nim, $id));
            return view('mahasiswa/pendaftaran-topik', [
                'detailsTopikSkripsi' => $this->model->getDetailTopikSkripsiByID($id),
                'listMahasiswa' => $this->model->getAllMahasiswaMendaftarTopikSkripsiByID($id),
                'allTopikSkripsi' => $this->model->getAllTopikSkripsi(),
                'ruleTopik' => $this->model->ruleAmbilTopik($nim, $id)
            ]);
        }
    }

    # Menetapkan mahasiswa terpilih
    public function decision(Request $request)
    {
        if ($request) {
            DB::update('UPDATE topik_tugas_akhir
            SET nim_terpilih_fk = ' . $request->radioNIM . ', 
                status = ' . config('constants.status_topik_skripsi.closed') . ',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ' . $request->inputHiddenIDTopikTugasAkhir);

            $mailData = DB::select('SELECT mhs.nama_mahasiswa, mhs.nim, mhs.email_mahasiswa, 
                topik.judul_topik, dosen.nama AS nama_dosen, bidang.topik_bidang,
                IF (mhs.nim = ' . $request->radioNIM . ', "terpilih", "tidak terpilih") AS keputusan 
                FROM ambil_topik_tugas_akhir ambil
                JOIN mahasiswa mhs ON mhs.nim=ambil.nim_fk_nim
                JOIN topik_tugas_akhir topik ON topik.id=ambil.topik_tugas_akhir_id
                JOIN dosen ON dosen.nipy=topik.nipy_fk_nipy
                JOIN topik_bidang bidang ON bidang.id=topik.topik_bidang_fk_id
                WHERE topik.id=' . $request->inputHiddenIDTopikTugasAkhir);

            # data untuk kirim email
            foreach ($mailData as $data) {
                $mailData['nama_mahasiswa'] = $data->nama_mahasiswa;
                $mailData['nim'] = $data->nim;
                $mailData['title'] = 'Penetapan Topik Tugas Akhir Mahasiswa';
                $mailData['keputusan'] = $data->keputusan;
                $mailData['judul_topik'] = $data->judul_topik;
                $mailData['nama_dosen'] = $data->nama_dosen;
                $mailData['topik_bidang'] = $data->topik_bidang;

                DB::update('UPDATE mahasiswa mhs 
                            JOIN ambil_topik_tugas_akhir ambil ON ambil.nim_fk_nim=mhs.nim 
                            SET mhs.status = ' . config('constants.status_mahasiswa.open') . ',
                                mhs.updated_at = ' . $this->curr . ' 
                            WHERE mhs.nim <> ' . $request->radioNIM);
                DB::update('UPDATE mahasiswa mhs 
                            JOIN ambil_topik_tugas_akhir ambil ON ambil.nim_fk_nim=mhs.nim 
                            SET mhs.status = ' . config('constants.status_mahasiswa.metopen') . ',
                                mhs.updated_at = ' . $this->curr . ' 
                            WHERE mhs.nim = ' . $request->radioNIM);

                // hidup matikan ketika hendak dicoba
                //Mail::to($data->email_mahasiswa)->send(new EmailMahasiswaTerpilih($mailData)); // technical debt: 1. nama penerima, 2. gunakan job queue khusus email
            }

            return redirect('/Topik/All')->with('success', 'Berhasil menetapkan mahasiswa terpilih.');
        } else {
            return redirect('/Topik/Details');
        }
    }

    # Menambah topik tugas akhir baru
    public function store(Request $request)
    {
        $request->validate([
            'topik_bidang' => 'required',
            'judul' => 'required|min:5',
            'deskripsi' => 'required|min:5',
        ]);

        if ($request) {
            $store = new TopikSkripsi();
            $store->nipy_fk_nipy = Session::get('nipy');
            $store->topik_bidang_fk_id = $request->topik_bidang;
            $store->judul_topik = $request->judul;
            $store->deskripsi = $request->deskripsi;
            $store->nim_terpilih_fk;
            $store->save();

            return redirect('/Topik/All')->with('success', 'Topik tugas akhir berhasil di tambahkan');;
        } else {
            return redirect('/Topik/Add');
        }
    }

    # function tampil data yang akan di update where data yang di pilih
    # selectOne menambil data 1 array by id
    public function updateTopikTA($id)
    {
        $topik = Topik::orderBy('topik_bidang', 'asc')->get();
        $data = $this->model->getTopikSkripsiByID($id);
        return view('edit_TA', compact('data', 'topik'));
    }

    #Function proses menyimpan data yang telah di edit
    public function aksiUpdateTA(Request $request, $id)
    {
        $request->validate([
            'topik_bidang_fk_id' => 'required',
            'judul_topik' => 'required|min:5',
            'deskripsi' => 'required|min:5',
        ]);

        TopikSkripsi::where('id', $id)->update([
            'topik_bidang_fk_id' => $request->topik_bidang_fk_id,
            'judul_topik'        => $request->judul_topik,
            'deskripsi'          => $request->deskripsi,
            'updated_at' => $this->curr
        ]);
        session()->flash('msg', 'Topik Skripsi berhasil di-update');
        return redirect('/Topik/All');
    }

    # menampilkan detail topik untuk page mendaftar topik buat mahasiswa
    public function daftarDetailTopik($id)
    {
        $nim = Session::get('nim');

        return view('mahasiswa/pendaftaran-topik', [
            'detailTopik' => $this->model->getDetailTopikSkripsiByID($id),
            'listMahasiswa' => $this->model->getAllMahasiswaMendaftarTopikSkripsiByID($id),
            'ruleTopik' => $this->model->ruleAmbilTopik($nim, $id)
        ]);
    }

    # menyimpan topik tugas akhir yang di ambil oleh mahasiswa 
    public function saveTopikMahasiswa(Request $request)
    {
        $request->validate([
            'inputHiddenIDTopikSkripsi' => 'required'
        ]);

        if ($request) {
            $store = new AmbilTopikTugasAkhir;
            $store->nim_fk_nim = Session::get('nim');
            $store->topik_tugas_akhir_id = $request->inputHiddenIDTopikSkripsi;
            $store->blocking_time = config('constants.blocking_time');
            $store->save();

            return redirect('/Topik/All/Mahasiswa')->with('success', 'Selamat, Anda berhasil mendaftar topik tugas akhir ');;
        } else {
            return redirect('/Topik/All/Mahasiswa');
        }
    }
}
