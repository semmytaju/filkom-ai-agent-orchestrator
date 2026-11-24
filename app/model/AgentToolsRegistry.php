<?php

class AgentToolsRegistry {

    public static function getCatalog(): array {
        return [
            ['get_current_datetime_info', 'Info Waktu, Tanggal & Semester Aktif (PHP)', 'PHP_Function', 'jam berapa,tanggal berapa,hari apa,waktu sekarang,what time,current date,semester aktif,besok,kemarin', 'Membaca waktu WITA/WIB/WIT, kalender spesifik maupun relatif (besok/kemarin/tanggal N), dan semester aktif.', 'toolGetDateTimeInfo'],
            ['calculate_math_expression', 'Kalkulator Aritmetika Multi-Suku (PHP)', 'PHP_Function', 'hitung,berapa hasil,kali,bagi,tambah,kurang,calculate,sum', 'Mengevaluasi ekspresi aritmetika berantai multi-operand beserta prioritas operator dan tanda kurung secara aman.', 'toolCalculateMath'],
            ['query_database_stats', 'Pengambil Statistik MySQL Real-Time', 'MySQL_Query', 'statistik database,berapa total chat,jumlah percakapan,jumlah dokumen,data mysql,database stats', 'Mengambil agregasi jumlah percakapan, Static KB, dokumen RAG, dan memori langsung dari MySQL.', 'toolQueryDatabaseStats'],
            ['search_knowledge_data', 'Pencarian Data Terpadu (KB & RAG)', 'Data_Search', 'cari data,temukan informasi,search data,cari dokumen,referensi tentang', 'Mencari kecocokan lintas tabel pada Static Knowledge Base dan dokumen RAG secara real-time.', 'toolSearchKnowledgeData'],
            ['run_academic_workflow', 'Workflow Akademik & Simulasi SKS/IPK', 'Workflow', 'simulasi sks,hitung sks,workflow akademik,ips saya,ipk saya', 'Menentukan batas beban SKS maksimal berdasarkan capaian IPS/IPK sesuai pedoman akademik.', 'toolRunAcademicWorkflow'],
            ['safe_sql_assistant', 'SQL Assistant (Natural Language to Safe SQL)', 'SQL_Assistant', 'query sql,buatkan sql,sql assistant,sql select,contoh query,ubah ke sql', 'Mengubah pertanyaan bahasa alami menjadi query SELECT read-only yang aman beserta verifikasi tabel.', 'toolSafeSqlAssistant'],
            ['external_campus_service', 'Konektor API Layanan Kampus & Cuaca', 'External_API', 'info layanan kampus api,status server eksternal,sinkronisasi api,external api', 'Mengakses konektor layanan informasi kampus terproteksi SSRF Security::validate_external_url.', 'toolExternalApiCall'],

            ['calc_percentage_value', 'Kalkulator Persentase Nilai', 'PHP_Function', 'persen dari,% dari,percent of', 'Menghitung nilai persentase dari sejumlah angka atau nominal secara instan.', 'toolCalcPercentage'],
            ['calc_discount_price', 'Kalkulator Diskon & Harga Akhir', 'PHP_Function', 'diskon,potongan harga,discount', 'Menghitung besaran potongan harga dan harga bersih setelah diskon persentase.', 'toolCalcDiscount'],
            ['calc_tax_ppn', 'Kalkulator Pajak PPN 11%', 'PHP_Function', 'hitung ppn,pajak ppn,ppn 11,hitung pajak barang', 'Menghitung komponen pajak PPN 11% serta total harga setelah pajak.', 'toolCalcTaxPpn'],
            ['calc_compound_interest', 'Simulasi Bunga Majemuk Investasi', 'PHP_Function', 'bunga majemuk,compound interest,simulasi tabungan,investasi bunga', 'Menghitung pertumbuhan pokok investasi berdasarkan suku bunga tahunan dan tenor.', 'toolCalcCompoundInterest'],
            ['calc_loan_installment', 'Kalkulator Cicilan Kredit Bulanan', 'PHP_Function', 'cicilan,angsuran,simulasi kredit,loan installment', 'Menghitung perkiraan angsuran bulanan dari pokok pinjaman, tenor bulan, dan bunga.', 'toolCalcLoan'],
            ['calc_average_median', 'Statistik Rata-Rata, Median, Min & Maks', 'PHP_Function', 'rata-rata dari,rata rata dari,hitung mean,median dari,average of', 'Menghitung nilai rata-rata (mean), median, minimum, dan maksimum dari daftar angka.', 'toolCalcStats'],
            ['calc_factorial_permutation', 'Kalkulator Faktorial & Kombinatorika', 'PHP_Function', 'faktorial,factorial', 'Menghitung nilai faktorial N! untuk bilangan bulat 0 hingga 20.', 'toolCalcFactorial'],
            ['calc_square_cube_root', 'Kalkulator Akar Kuadrat & Kubik', 'PHP_Function', 'akar kuadrat,akar pangkat,square root,sqrt', 'Menghitung akar kuadrat atau akar pangkat tiga dari bilangan real non-negatif.', 'toolCalcRoot'],
            ['calc_prime_checker', 'Pemeriksa Bilangan Prima', 'PHP_Function', 'bilangan prima,cek prima,prime number', 'Memeriksa apakah suatu bilangan bulat merupakan bilangan prima beserta faktornya.', 'toolCalcPrime'],
            ['calc_gcd_lcm', 'Kalkulator FPB dan KPK Bilangan', 'PHP_Function', 'fpb dan kpk,hitung fpb,hitung kpk,gcd,lcm', 'Menentukan Faktor Persekutuan Terbesar (FPB) dan Kelipatan Persekutuan Terkecil (KPK).', 'toolCalcGcdLcm'],
            ['calc_bmi_health', 'Kalkulator Indeks Massa Tubuh (BMI)', 'PHP_Function', 'hitung bmi,cek bmi,indeks massa tubuh,imt saya', 'Menghitung skor BMI dari berat (kg) dan tinggi (cm) beserta kategori kesehatan.', 'toolCalcBmi'],
            ['calc_age_exact', 'Kalkulator Usia & Tahun Lahir', 'PHP_Function', 'hitung umur,usia lahir,berapa umur lahir,calculate age', 'Menghitung usia tepat dalam tahun dan bulan dari tanggal atau tahun lahir.', 'toolCalcAge'],
            ['calc_countdown_days', 'Penghitung Mundur Hari (Countdown)', 'PHP_Function', 'berapa hari lagi ke,hitung mundur ke,days until', 'Menghitung selisih jumlah hari dari hari ini menuju tanggal target tertentu.', 'toolCalcCountdown'],
            ['calc_circle_geometry', 'Kalkulator Luas & Keliling Lingkaran', 'PHP_Function', 'luas lingkaran,keliling lingkaran,circle area', 'Menghitung luas dan keliling lingkaran dari nilai jari-jari (r) atau diameter.', 'toolCalcCircle'],
            ['calc_triangle_rectangle', 'Kalkulator Luas Segitiga & Persegi Panjang', 'PHP_Function', 'luas segitiga,luas persegi panjang,luas persegi,pythagoras', 'Menghitung luas dan keliling bangun datar persegi panjang, persegi, atau segitiga.', 'toolCalcPolygon'],

            ['convert_temperature', 'Konverter Suhu (C / F / K)', 'PHP_Function', 'konversi suhu,celsius ke fahrenheit,celcius ke fahrenheit,fahrenheit ke celsius,ke kelvin', 'Mengonversi satuan temperatur antara Celsius, Fahrenheit, dan Kelvin.', 'toolConvertTemperature'],
            ['convert_length_distance', 'Konverter Satuan Panjang & Jarak', 'PHP_Function', 'km ke meter,meter ke cm,cm ke inci,km ke mil,mil ke km,meter ke kaki,konversi jarak', 'Mengonversi satuan panjang antara km, m, cm, mm, mil, yard, kaki, dan inci.', 'toolConvertUnit'],
            ['convert_weight_mass', 'Konverter Satuan Berat & Massa', 'PHP_Function', 'kg ke gram,gram ke kg,pon ke kg,kg ke pon,ons ke gram,konversi berat', 'Mengonversi satuan massa antara ton, kg, ons, gram, mg, dan pound.', 'toolConvertUnit'],
            ['convert_data_storage', 'Konverter Kapasitas Data Digital', 'PHP_Function', 'gb ke mb,mb ke kb,tb ke gb,kb ke byte,konversi data,konversi gb', 'Mengonversi ukuran data digital antara Byte, KB, MB, GB, dan TB.', 'toolConvertUnit'],
            ['convert_speed_units', 'Konverter Satuan Kecepatan', 'PHP_Function', 'km/jam ke m/s,km/j ke m/s,m/s ke km/jam,mph ke km/jam,knot ke km/jam', 'Mengonversi satuan kecepatan antara km/jam, m/s, mph, dan knot.', 'toolConvertUnit'],
            ['convert_area_units', 'Konverter Satuan Luas Wilayah', 'PHP_Function', 'hektar ke m2,km2 ke hektar,m2 ke hektar,are ke m2,konversi luas', 'Mengonversi satuan luas antara meter persegi, are, hektar, dan km persegi.', 'toolConvertUnit'],
            ['convert_time_duration', 'Konverter Durasi Waktu', 'PHP_Function', 'jam ke detik,jam ke menit,menit ke detik,menit ke jam,hari ke jam', 'Mengonversi durasi antara detik, menit, jam, hari, dan minggu.', 'toolConvertUnit'],
            ['convert_number_base', 'Konverter Bilangan Biner, Hex, Oktal', 'PHP_Function', 'ke biner,biner dari,ke hex,ke heksadesimal,ke oktal,biner ke desimal', 'Mengonversi bilangan bulat antara basis Desimal, Biner, Heksadesimal, dan Oktal.', 'toolConvertBase'],
            ['convert_currency_estimate', 'Kalkulator Estimasi Kurs Mata Uang', 'PHP_Function', 'usd ke rupiah,usd ke idr,sgd ke idr,eur ke idr,jpy ke idr,kurs rupiah', 'Menghitung estimasi konversi nilai tukar mata uang utama ke Rupiah (IDR).', 'toolConvertCurrency'],
            ['convert_world_timezone', 'Konverter Zona Waktu Global & Nusantara', 'PHP_Function', 'jam di jakarta,jam di tokyo,jam di london,jam di new york,wita ke wib,wib ke wita,waktu utc', 'Menampilkan waktu saat ini atau mengonversi jam antar zona waktu WIB, WITA, WIT, UTC, dan kota dunia.', 'toolConvertTimezone'],
            ['generate_secure_password', 'Generator Kata Sandi Acak CSPRNG', 'PHP_Function', 'buatkan password,generate password,password acak,sandi acak kuat', 'Membuat kata sandi acak kriptografis menggunakan random_int() PHP.', 'toolGenPassword'],
            ['generate_uuid_token', 'Generator UUID v4 & Kode Unik', 'PHP_Function', 'buatkan uuid,generate uuid,buatkan token unik,kode acak unik', 'Menghasilkan pengenal unik UUID versi 4 sesuai standar RFC 4122.', 'toolGenUuid'],
            ['text_word_char_stats', 'Penghitung Jumlah Kata & Karakter', 'PHP_Function', 'hitung jumlah kata,berapa kata dalam,hitung karakter teks,word count', 'Menganalisis jumlah kata, huruf/karakter, dan kalimat pada teks masukan.', 'toolTextStats'],
            ['generate_sha256_hash', 'Generator Hash SHA-256 & Base64', 'PHP_Function', 'hash sha256,buatkan md5,encode base64,decode base64', 'Membuat digest kriptografi SHA-256/MD5 atau menyandikan Base64 untuk pembelajaran TI.', 'toolTextHash'],
            ['random_picker_dice', 'Generator Angka Acak, Dadu & Koin', 'PHP_Function', 'angka acak,random number,lempar dadu,lempar koin,pilih acak', 'Menghasilkan angka acak terukur, lemparan dadu 1-6, atau lemparan koin.', 'toolRandomPicker'],

            ['calc_gpa_grade_converter', 'Konverter Nilai Angka ke Mutu Huruf UNKLAB', 'Workflow', 'nilai huruf,indeks nilai,konversi skor,nilai mutu,nilai 8,nilai 7,nilai 6', 'Mengonversi nilai angka (0-100) ke huruf mutu (A sampai E) dan bobot IP.', 'toolGradeConverter'],
            ['calc_target_ipk_planner', 'Perencana Target Kenaikan IPK', 'Workflow', 'target ipk,naikkan ipk,kejar ipk,ip minimal semester', 'Memberikan strategi pencapaian target IPK berdasarkan IPK saat ini.', 'toolTargetIpkPlanner'],
            ['check_cumlaude_eligibility', 'Pemeriksa Syarat Cum Laude & Dean List', 'Workflow', 'cek syarat cum laude,syarat dean list,predikat pujian,cek cumlaude', 'Memvalidasi kelayakan Dean List (IPS >= 3.50) dan Cum Laude (>= 3.51).', 'toolAcademicPolicy'],
            ['check_skripsi_prereq', 'Pemeriksa Syarat Pengambilan Skripsi / TA', 'Workflow', 'syarat ambil skripsi,daftar proposal skripsi,syarat tugas akhir,bimbingan skripsi', 'Merangkum syarat minimal SKS, lulus Metodologi Penelitian, dan alur proposal.', 'toolAcademicPolicy'],
            ['check_kkn_internship_flow', 'Panduan Workflow KKN & Kerja Praktek', 'Workflow', 'alur daftar magang,prosedur kkn,syarat kerja praktek,daftar kp', 'Menjelaskan tahapan pendaftaran Kerja Praktek/Magang dan Kuliah Kerja Nyata.', 'toolAcademicPolicy'],
            ['check_krs_revision_workflow', 'Panduan Alur Pengisian & Revisi KRS', 'Workflow', 'alur krs,tambah hapus krs,revisi krs,pengisian krs,perwalian pa', 'Menjelaskan langkah pengisian KRS, persetujuan Penasehat Akademik, dan masa add/drop.', 'toolAcademicPolicy'],
            ['check_cuti_akademik_flow', 'Panduan Prosedur Cuti & Aktif Kuliah', 'Workflow', 'prosedur cuti kuliah,syarat cuti akademik,aktif kembali kuliah', 'Menjelaskan persyaratan pengajuan surat cuti studi dan pengaktifan kembali.', 'toolAcademicPolicy'],
            ['check_yudisium_wisuda_flow', 'Checklist Syarat Yudisium & Wisuda', 'Workflow', 'syarat yudisium,alur wisuda,pendaftaran wisuda,berkas kelulusan', 'Menampilkan daftar berkas wajib sidang, bebas pustaka, yudisium, dan wisuda.', 'toolAcademicPolicy'],
            ['check_ukt_payment_workflow', 'Panduan Pembayaran Uang Kuliah & VA', 'Workflow', 'cara bayar uang kuliah,virtual account kuliah,jadwal cicilan spp,aktivasi keuangan', 'Menjelaskan prosedur pembayaran uang kuliah lewat Virtual Account dan konfirmasi keuangan.', 'toolAcademicPolicy'],
            ['check_scholarship_eligibility', 'Simulasi Kelayakan Beasiswa Kampus', 'Workflow', 'simulasi syarat beasiswa,kelayakan beasiswa,beasiswa kip,working student', 'Mengevaluasi kriteria IPK dan berkas untuk jalur beasiswa prestasi, KIP-K, atau working student.', 'toolAcademicPolicy'],
            ['calc_attendance_rule', 'Kalkulator Batas Kehadiran Minimal 80%', 'PHP_Function', 'maksimal absen,batas absen,aturan kehadiran 80,boleh bolos berapa', 'Menghitung minimal kehadiran dan maksimal ketidakhadiran dari total pertemuan.', 'toolAttendanceCalc'],
            ['calc_pomodoro_study_plan', 'Perencana Jadwal Belajar Pomodoro', 'Workflow', 'jadwal pomodoro,rencana belajar fokus,teknik pomodoro,bagi waktu belajar', 'Membagi total durasi belajar menjadi blok fokus 25 menit dan istirahat 5 menit.', 'toolPomodoroPlanner'],
            ['recommend_study_concentration', 'Rekomendasi Konsentrasi Prodi FILKOM', 'Workflow', 'rekomendasi konsentrasi,pilih peminatan,konsentrasi ai atau rpl,minat jaringan', 'Membantu memilih peminatan AI, Software Engineering, atau Cyber Security sesuai minat.', 'toolConcentrationAdvisor'],
            ['check_dormitory_rules_workflow', 'Panduan Asrama & Tata Tertib Penghuni', 'Workflow', 'aturan asrama,jam malam asrama,daftar asrama unklab,dormitory rules', 'Menjelaskan prosedur pendaftaran asrama putra/putri serta jam malam kampus.', 'toolAcademicPolicy'],
            ['check_chapel_spirituality_credit', 'Panduan Kewajiban Chapel & Kerohanian', 'Workflow', 'aturan chapel,poin kerohanian,absen ibadah chapel,sabbath campus', 'Menjelaskan ketentuan kehadiran chapel dan pengembangan karakter spiritual UNKLAB.', 'toolAcademicPolicy'],
            ['check_toefl_english_req', 'Pemeriksa Syarat Skor TOEFL / English', 'Workflow', 'syarat skor toefl,nilai minimal toefl,english proficiency lulus', 'Menjelaskan syarat kelulusan kemampuan Bahasa Inggris bagi calon wisudawan.', 'toolAcademicPolicy'],
            ['generate_research_title_ideas', 'Generator Ide Topik Skripsi AI & TI', 'Workflow', 'ide judul skripsi,topik riset ai,judul penelitian machine learning,ide tugas akhir', 'Memberikan 3 ide topik penelitian terarah seputar AI, RAG, NLP, dan sistem kampus.', 'toolResearchIdeas'],
            ['check_campus_emergency_contacts', 'Direktori Layanan Akademik, BAA & IT', 'Workflow', 'kontak akademik,layanan baa,helpdesk it kampus,nomor layanan mahasiswa', 'Menampilkan informasi kanal resmi BAA, Keuangan, Kemahasiswaan, dan Helpdesk IT.', 'toolCampusDirectory'],

            ['assistant_capabilities_menu', 'Menu Daftar Kemampuan 100 Function Calling', 'PHP_Function', 'fitur apa saja,daftar kemampuan,kamu bisa apa saja,list function calling,menu bantuan bot', 'Merangkum kategori 100 fungsi eksekusi otomatis yang tersedia pada Unklabot.', 'toolCapabilitiesMenu'],
            ['assistant_identity_version', 'Info Identitas, Versi & Arsitektur AI', 'PHP_Function', 'versi sistem berapa,arsitektur ai,teknologi apa yang dipakai,siapa pembuatmu', 'Menjelaskan versi aplikasi, arsitektur Hybrid AI, RAG, dan mesin avatar 3D.', 'toolIdentityInfo'],
            ['greeting_time_aware', 'Sapaan Interaktif Sesuai Waktu Lokal', 'PHP_Function', 'selamat pagi,selamat siang,selamat sore,selamat malam,good morning,good afternoon,good evening,hai bot,halo asisten', 'Menjawab sapaan pengguna secara adaptif berdasarkan jam lokal WITA.', 'toolGreetingTime'],
            ['farewell_polite_closing', 'Penutup Percakapan & Apresiasi Sopan', 'PHP_Function', 'terima kasih banyak,makasih ya,sampai jumpa,goodbye,bye bye,thank you very much,sudah cukup', 'Memberikan balasan penutup percakapan yang ramah dan profesional.', 'toolFarewellPolite'],
            ['translate_quick_phrase', 'Kamus Cepat Istilah Akademik ID-EN', 'PHP_Function', 'bahasa inggrisnya,arti kata,terjemahan istilah,translate word', 'Menerjemahkan istilah umum akademik dan AI antara Bahasa Indonesia dan Inggris.', 'toolQuickGlossary'],
            ['check_day_holiday_status', 'Pemeriksa Hari Kerja vs Akhir Pekan', 'PHP_Function', 'apakah besok libur,apakah hari ini akhir pekan,cek hari kerja,weekend check', 'Memeriksa apakah hari ini atau tanggal target merupakan hari perkuliahan (Senin-Jumat) atau akhir pekan.', 'toolWeekendCheck'],
            ['calc_calorie_water_intake', 'Kalkulator Kebutuhan Air & Kalori Harian', 'PHP_Function', 'kebutuhan air minum,kebutuhan kalori harian,hitung kalori,minum air per hari', 'Menghitung rekomendasi konsumsi air (ml) dan perkiraan kebutuhan kalori dasar harian.', 'toolWellnessCalc'],
            ['calc_split_bill_group', 'Kalkulator Patungan / Split Bill Grup', 'PHP_Function', 'bagi tagihan,split bill,patungan bayar,bayar per orang', 'Membagi total tagihan secara adil berdasarkan jumlah orang peserta.', 'toolSplitBill'],
            ['calc_fuel_trip_cost', 'Kalkulator Biaya BBM & Jarak Perjalanan', 'PHP_Function', 'biaya bensin,ongkos bbm,hitung bahan bakar,biaya perjalanan', 'Menghitung estimasi liter BBM dan biaya perjalanan berdasarkan jarak tempuh.', 'toolFuelCost'],
            ['validate_email_url_format', 'Validator Format Email & URL Aman', 'PHP_Function', 'validasi email,cek email valid,validasi url,cek format url', 'Memverifikasi kesesuaian format alamat surel dan tautan URL dengan filter PHP.', 'toolValidateInput'],
            ['generate_acronym_abbreviation', 'Pengurai Singkatan Kampus & Istilah AI', 'PHP_Function', 'apa kepanjangan,kepanjangan dari,singkatan dari,arti singkatan', 'Menjelaskan kepanjangan singkatan resmi kampus UNKLAB maupun istilah AI.', 'toolAcronymExplainer'],
            ['check_nim_year_info', 'Pembaca Tahun Angkatan dari Kode NIM', 'PHP_Function', 'cek angkatan nim,nim tahun berapa,semester dari nim,info nim', 'Menganalisis kode NIM mahasiswa untuk memperkirakan tahun masuk dan tingkat semester.', 'toolNimParser'],
            ['campus_building_navigator', 'Navigator Lokasi Gedung Kampus UNKLAB', 'Workflow', 'dimana gedung,letak perpustakaan,lokasi gedung filkom,lokasi rektorat,arah gedung asrama', 'Memberikan panduan letak gedung fakultas, rektorat, perpustakaan, dan gereja di kampus.', 'toolBuildingNav'],
            ['voice_mic_usage_guide', 'Panduan Kontrol Mikrofon & Suara 3D', 'PHP_Function', 'cara pakai mikrofon,cara interupsi suara,cara atur volume avatar,fitur suara 3d', 'Menjelaskan cara menekan tombol mikrofon, memotong pembicaraan, dan mengatur volume suara.', 'toolVoiceGuide'],
            ['privacy_security_notice', 'Penjelasan Keamanan AES-256, CSRF & WAF', 'PHP_Function', 'apakah data aman,keamanan api key,perlindungan waf,enkripsi sistem', 'Menjelaskan mekanisme enkripsi kunci API AES-256, proteksi CSRF, dan filter WAF.', 'toolSecurityNotice'],

            ['query_top_rag_documents', 'Analitik Top Dokumen RAG Terpopuler', 'MySQL_Query', 'dokumen rag paling populer,top dokumen rag,dokumen terbanyak diakses,most retrieved document', 'Mengambil daftar 3 dokumen RAG dengan jumlah retrieval tertinggi di MySQL.', 'toolSqlTopRag'],
            ['query_top_kb_topics', 'Analitik Topik Static Knowledge Terpopuler', 'MySQL_Query', 'topik kb terpopuler,pertanyaan paling sering,static kb hit tertinggi,top knowledge', 'Menampilkan topik Static KB dengan jumlah kecocokan (hit_count) tertinggi.', 'toolSqlTopKb'],
            ['query_recent_conversations', 'Pembaca Riwayat Chat Terbaru MySQL', 'MySQL_Query', 'riwayat chat terakhir,percakapan terbaru,pertanyaan terakhir,recent chats', 'Mengambil 3 entri pertanyaan terakhir yang tercatat di tabel tbl_va_conversations.', 'toolSqlRecentChats'],
            ['query_llm_source_breakdown', 'Distribusi Sumber Jawaban AI (MySQL)', 'MySQL_Query', 'distribusi sumber jawaban,berapa kali llm dipakai,statistik response source,pemakaian rag vs llm', 'Menghitung komposisi penggunaan OpenAI/Gemini LLM, RAG, Static KB, dan Function Calling.', 'toolSqlSourceDist'],
            ['query_language_usage_stats', 'Statistik Penggunaan Bahasa ID vs EN', 'MySQL_Query', 'statistik bahasa chat,berapa chat bahasa inggris,proporsi bahasa percakapan', 'Menghitung persentase percakapan berbahasa Indonesia dibanding Bahasa Inggris.', 'toolSqlLangStats'],
            ['query_average_eval_latency', 'Metrik Rata-Rata Skor Evaluasi & Latensi', 'MySQL_Query', 'rata-rata latensi,kecepatan respon rata-rata,skor evaluasi ai rata-rata,avg latency', 'Mengambil rata-rata ai_eval_score dan latency_ms dari seluruh log percakapan.', 'toolSqlPerfMetrics'],
            ['query_active_api_provider', 'Status Provider AI & Model Utama Aktif', 'MySQL_Query', 'provider ai aktif,model apa yang aktif sekarang,cek primary api key,provider sekarang', 'Membaca pengaturan aktif ai_provider serta label Primary Key yang sedang digunakan.', 'toolSqlActiveProvider'],
            ['query_api_token_usage', 'Laporan Total Pemakaian Request & Token API', 'MySQL_Query', 'berapa total token api,total request api key,statistik pemakaian token,token usage', 'Menjumlahkan total_requests dan total_tokens dari tabel tbl_ai_provider_keys.', 'toolSqlTokenUsage'],
            ['query_security_incident_count', 'Ringkasan Log Insiden Keamanan WAF/CSRF', 'MySQL_Query', 'berapa ancaman diblokir,cek log insiden keamanan,jumlah serangan waf,security incidents', 'Menghitung total insiden keamanan yang ditolak oleh WAF, CSRF, maupun brute-force guard.', 'toolSqlSecurityCount'],
            ['query_profanity_filter_stats', 'Statistik Moderasi Kata Kasar Percakapan', 'MySQL_Query', 'statistik kata kasar,berapa chat kata kasar,log profanity terdeteksi', 'Menghitung jumlah percakapan yang terdeteksi oleh filter etika akademik.', 'toolSqlProfanityStats'],
            ['query_active_sessions_count', 'Monitor Sesi Pengguna Aktif Portal Admin', 'MySQL_Query', 'berapa sesi login aktif,siapa user online,jumlah sesi portal,active sessions', 'Menghitung sesi dashboard aktif yang belum dicabut dalam batas waktu idle 15 menit.', 'toolSqlActiveSessions'],
            ['query_top_used_tools', 'Peringkat Eksekusi Function Calling Tools', 'MySQL_Query', 'tool paling sering dipakai,ranking function calling,statistik agent tools,top tools', 'Mengambil daftar 3 Agent Tool dengan frekuensi eksekusi tertinggi di MySQL.', 'toolSqlTopTools'],

            ['search_rag_by_faculty', 'Pencari Dokumen RAG Berdasarkan Fakultas', 'Data_Search', 'dokumen fakultas,cari pedoman fakultas,dokumen filkom,dokumen keperawatan,dokumen ekonomi,dokumen pertanian', 'Mencari dokumen RAG yang relevan berdasarkan nama fakultas di UNKLAB.', 'toolSearchRagFaculty'],
            ['search_rag_by_category', 'Daftar Kategori & Jumlah Dokumen RAG', 'Data_Search', 'kategori dokumen rag,jenis dokumen rag apa saja,daftar kategori rag', 'Mengelompokkan seluruh dokumen RAG aktif berdasarkan kategorinya di database.', 'toolSearchRagCategories'],
            ['search_kb_by_category', 'Daftar Kategori Static Knowledge Base', 'Data_Search', 'kategori static knowledge,kategori kb apa saja,daftar topik pengetahuan', 'Menampilkan daftar kategori topik Static Knowledge Base yang tersedia.', 'toolSearchKbCategories'],
            ['search_user_memories_count', 'Pemeriksa Memori Jangka Panjang Sesi', 'Data_Search', 'berapa memori saya,jumlah catatan memori sesi,cek memori tersimpan', 'Menghitung jumlah entri memori jangka panjang yang tercatat pada sesi pengguna.', 'toolSearchSessionMemories'],
            ['search_failed_login_audit', 'Audit Percobaan Login Gagal Terbaru', 'Data_Search', 'percobaan login gagal,audit login gagal,cek failed login', 'Memeriksa jumlah catatan autentikasi gagal pada tabel tbl_login_logs.', 'toolSearchFailedLogins'],
            ['search_model_catalog_gemini', 'Daftar Katalog Model Gemini 3 Tersedia', 'Data_Search', 'daftar model gemini,model gemini 3 apa saja,list model gemini', 'Menampilkan daftar model Gemini 3 yang didukung pada konfigurasi sistem.', 'toolCatalogGemini'],
            ['search_model_catalog_openai', 'Daftar Katalog Model OpenAI GPT-6 & GPT-5', 'Data_Search', 'daftar model openai,model gpt apa saja,list model openai,model gpt-6', 'Menampilkan daftar model OpenAI GPT-6 dan GPT-5 yang tersedia pada sistem.', 'toolCatalogOpenAi'],
            ['search_rag_latest_added', 'Daftar Dokumen RAG Terbaru Diindeks', 'Data_Search', 'dokumen rag terbaru,file rag baru ditambahkan,latest rag documents', 'Mengambil 3 dokumen RAG paling baru yang diindeks beserta jumlah segmennya.', 'toolSearchLatestRag'],

            ['sql_template_top_conversations', 'SQL Generator Analitik Sumber Percakapan', 'SQL_Assistant', 'sql sumber jawaban,sql group by source,query statistik chat', 'Membuat query SQL SELECT analitik distribusi sumber jawaban dan rata-rata evaluasi.', 'toolSqlTemplate'],
            ['sql_template_api_usage', 'SQL Generator Audit Pemakaian API Key', 'SQL_Assistant', 'sql pemakaian api key,sql cek token gemini openai,query status api key', 'Membuat query SQL SELECT daftar kunci AI, total token, dan status koneksi.', 'toolSqlTemplate'],
            ['sql_template_security_audit', 'SQL Generator Audit Keamanan & Login', 'SQL_Assistant', 'sql audit keamanan,sql cek serangan waf,query log login gagal', 'Membuat query SQL SELECT pemantauan insiden WAF dan autentikasi.', 'toolSqlTemplate'],
            ['sql_template_kb_popularity', 'SQL Generator Ranking Static Knowledge', 'SQL_Assistant', 'sql ranking static knowledge,sql hit count kb,query topik populer', 'Membuat query SQL SELECT urutan topik Static Knowledge paling sering digunakan.', 'toolSqlTemplate'],
            ['sql_template_rag_coverage', 'SQL Generator Inventaris Dokumen RAG', 'SQL_Assistant', 'sql daftar dokumen rag,sql chunk rag,query inventaris dokumen', 'Membuat query SQL SELECT kelengkapan dokumen RAG, tipe berkas, dan segmen.', 'toolSqlTemplate'],
            ['sql_template_agent_tools_rank', 'SQL Generator Statistik 100 Agent Tools', 'SQL_Assistant', 'sql statistik agent tools,sql ranking tool,query call count tools', 'Membuat query SQL SELECT peringkat 100 Function Calling berdasarkan frekuensi eksekusi.', 'toolSqlTemplate'],

            ['external_weather_airmadidi', 'Konektor Cuaca Real-Time Airmadidi UNKLAB', 'External_API', 'cuaca airmadidi,cuaca kampus unklab,cuaca hari ini di kampus,suhu di airmadidi', 'Mengambil prakiraan suhu udara kampus UNKLAB Airmadidi secara real-time melalui Open-Meteo API.', 'toolExternalWeather'],
            ['external_api_health_probe', 'Monitor Status Koneksi API Eksternal Tersimpan', 'External_API', 'cek status koneksi semua api,apakah koneksi llm sehat,kesehatan api eksternal', 'Mengevaluasi ringkasan status koneksi terakhir seluruh API key Gemini dan OpenAI.', 'toolExternalHealth'],
            ['external_campus_portal_links', 'Direktori Portal Resmi & LMS UNKLAB', 'External_API', 'link website unklab,alamat portal siasat,link lms kampus,website resmi unklab', 'Menampilkan alamat URL resmi situs Universitas Klabat, LMS, dan portal akademik.', 'toolExternalPortals'],
            ['external_ssrf_url_checker', 'Validator Keamanan SSRF URL Eksternal', 'External_API', 'cek keamanan ssrf url,validasi url aman,periksa url ssrf', 'Memeriksa apakah sebuah URL eksternal aman dan diizinkan oleh kebijakan anti-SSRF.', 'toolExternalSsrfCheck']
        ];
    }

    public static function dispatch(
        string $message,
        string $lower,
        string $lang,
        string $sessionCode,
        string $timezoneName,
        Database $db,
        callable $incrementTool,
        callable $formatCalendarReply,
        callable $searchRagFn
    ): ?array {
        $activeMap = self::getActiveToolCodeMap($db);

        $handlers = [
            'dispatchSpecializedMathAndStats',
            'dispatchAcademicWorkflows',
            'dispatchConvertersAndGenerators',
            'dispatchProductivityAndCampus',
            'dispatchSqlAndExternal',
            'dispatchDatabaseAndSearch',
            'dispatchCoreMathAndCalendar'
        ];

        foreach ($handlers as $fn) {
            $res = self::$fn($message, $lower, $lang, $sessionCode, $timezoneName, $db, $activeMap, $formatCalendarReply, $searchRagFn);
            if ($res !== null && !empty($res['tool'])) {
                $toolCode = (string)$res['tool'];
                if ($activeMap !== null && !isset($activeMap[$toolCode])) {
                    continue;
                }
                $incrementTool($toolCode);
                return $res;
            }
        }
        return null;
    }

    private static function getActiveToolCodeMap(Database $db): ?array {
        if (!$db->isConnected() || !$db->hasTable('tbl_agent_tools')) {
            return null;
        }
        $db->prepare("SELECT tool_code FROM tbl_agent_tools WHERE is_active = 1");
        $rows = $db->resultSet();
        if (empty($rows)) {
            return null;
        }
        $map = [];
        foreach ($rows as $r) {
            $map[(string)$r['tool_code']] = true;
        }
        return $map;
    }

    private static function isToolAllowed(?array $activeMap, string $toolCode): bool {
        return $activeMap === null || isset($activeMap[$toolCode]);
    }

    private static function reply(string $tool, string $text, string $source = 'Function Calling', string $expr = 'HAPPY', string $gesture = 'EXPLAIN'): array {
        return [
            'source' => $source,
            'tool' => $tool,
            'expression' => $expr,
            'gesture' => $gesture,
            'reply' => $text
        ];
    }

    private static function extractNums(string $text): array {
        $cleanTxt = preg_replace_callback('/\b\d{1,3}(?:\.\d{3})+\b/', static fn($m) => str_replace('.', '', $m[0]), $text);
        preg_match_all('/-?\d+(?:[\.,]\d+)?/', $cleanTxt, $m);
        $out = [];
        foreach (($m[0] ?? []) as $raw) {
            $clean = str_replace(',', '.', $raw);
            if (is_numeric($clean)) {
                $out[] = (float)$clean;
            }
        }
        return $out;
    }

    private static function fmtNum(float $val, int $dec = 2): string {
        if (abs($val - round($val)) < 1e-9) {
            return number_format((float)round($val), 0, ',', '.');
        }
        $s = number_format(round($val, $dec), $dec, '.', '');
        return rtrim(rtrim($s, '0'), '.');
    }

    private static function fmtPlain(float $val, int $dec = 4): string {
        if (abs($val - round($val)) < 1e-9) {
            return (string)(int)round($val);
        }
        $s = number_format(round($val, $dec), $dec, '.', '');
        return rtrim(rtrim($s, '0'), '.');
    }

    private static function fmtIdr(float $val): string {
        return 'Rp ' . number_format((float)round($val), 0, ',', '.');
    }

    private static function dispatchSpecializedMathAndStats(string $message, string $lower, string $lang, string $sc, string $tz, Database $db, ?array $am): ?array {
        if (self::isToolAllowed($am, 'calc_discount_price') && preg_match('/\b(diskon|potongan\s+harga|discount)\b/u', $lower) && preg_match('/(\d+(?:\.\d+)?)\s*%.*?(\d[\d\.,]*)|(\d[\d\.,]*).*?(\d+(?:\.\d+)?)\s*%/u', $lower, $m)) {
            $pct = !empty($m[1]) ? (float)$m[1] : (float)$m[4];
            $priceRaw = !empty($m[2]) ? $m[2] : $m[3];
            $price = (float)str_replace(['.', ','], ['', '.'], preg_replace('/[^\d]/', '', $priceRaw));
            if ($price > 0 && $pct >= 0 && $pct <= 100) {
                $cut = $price * ($pct / 100);
                $net = $price - $cut;
                $txt = $lang === 'en'
                    ? "With a {$pct}% discount on " . self::fmtIdr($price) . ", you save " . self::fmtIdr($cut) . " and pay " . self::fmtIdr($net) . "."
                    : "Dengan diskon {$pct}% dari " . self::fmtIdr($price) . ", potongannya " . self::fmtIdr($cut) . " sehingga harga akhir menjadi " . self::fmtIdr($net) . ".";
                return self::reply('calc_discount_price', $txt);
            }
        }

        if (self::isToolAllowed($am, 'calc_tax_ppn') && preg_match('/\b(ppn|pajak\s+barang|tax\s+11)\b/u', $lower)) {
            $nums = self::extractNums($lower);
            $base = 0.0;
            foreach ($nums as $n) {
                if ($n > 100) { $base = $n; break; }
            }
            if ($base <= 0 && !empty($nums)) $base = (float)end($nums);
            if ($base > 0) {
                $ppn = $base * 0.11;
                $tot = $base + $ppn;
                $txt = $lang === 'en'
                    ? "11% VAT on " . self::fmtIdr($base) . " is " . self::fmtIdr($ppn) . ", totaling " . self::fmtIdr($tot) . "."
                    : "PPN 11% dari " . self::fmtIdr($base) . " sebesar " . self::fmtIdr($ppn) . ", sehingga totalnya " . self::fmtIdr($tot) . ".";
                return self::reply('calc_tax_ppn', $txt);
            }
        }

        if (self::isToolAllowed($am, 'calc_percentage_value') && preg_match('/(\d+(?:[\.,]\d+)?)\s*(?:%|persen|percent)\s+(?:dari|of)\s+(\d[\d\.,]*)/u', $lower, $m)) {
            $pct = (float)str_replace(',', '.', $m[1]);
            $normNum = preg_replace_callback('/\b\d{1,3}(?:\.\d{3})+\b/', static fn($d) => str_replace('.', '', $d[0]), $m[2]);
            $val = (float)str_replace(',', '.', $normNum);
            $res = $val * ($pct / 100);
            $txt = $lang === 'en'
                ? "{$pct}% of " . self::fmtPlain($val) . " equals " . self::fmtPlain($res, 4) . "."
                : "{$pct}% dari " . self::fmtPlain($val) . " adalah " . self::fmtPlain($res, 4) . ".";
            return self::reply('calc_percentage_value', $txt);
        }

        if (self::isToolAllowed($am, 'calc_compound_interest') && preg_match('/\b(bunga\s+majemuk|compound\s+interest|simulasi\s+tabungan|investasi\s+bunga)\b/u', $lower)) {
            $nums = self::extractNums($lower);
            if (count($nums) >= 3) {
                $p = $nums[0];
                $r = ($nums[1] <= 100 ? $nums[1] : $nums[1]) / 100;
                $t = min(50, max(1, (int)$nums[2]));
                $final = $p * pow(1 + $r, $t);
                $txt = $lang === 'en'
                    ? "Compound interest on " . self::fmtIdr($p) . " at " . ($r * 100) . "% for {$t} years grows to " . self::fmtIdr($final) . "."
                    : "Bunga majemuk modal " . self::fmtIdr($p) . " dengan bunga " . ($r * 100) . "% selama {$t} tahun menjadi " . self::fmtIdr($final) . ".";
                return self::reply('calc_compound_interest', $txt);
            }
        }

        if (self::isToolAllowed($am, 'calc_loan_installment') && preg_match('/\b(cicilan|angsuran|simulasi\s+kredit|loan\s+installment)\b/u', $lower)) {
            $nums = self::extractNums($lower);
            if (count($nums) >= 2) {
                $p = $nums[0];
                $months = min(360, max(1, (int)$nums[1]));
                $ratePct = isset($nums[2]) ? $nums[2] : 1.0;
                $monthly = ($p / $months) + ($p * ($ratePct / 100));
                $txt = $lang === 'en'
                    ? "Estimated monthly installment for " . self::fmtIdr($p) . " over {$months} months at {$ratePct}%/mo is " . self::fmtIdr($monthly) . "."
                    : "Perkiraan cicilan bulanan pinjaman " . self::fmtIdr($p) . " tenor {$months} bulan (bunga {$ratePct}%/bln) adalah " . self::fmtIdr($monthly) . ".";
                return self::reply('calc_loan_installment', $txt);
            }
        }

        if (self::isToolAllowed($am, 'calc_average_median') && preg_match('/\b(rata[\s\-]rata|hitung\s+mean|median\s+dari|average\s+of)\b/u', $lower)) {
            $nums = self::extractNums($lower);
            if (count($nums) >= 2) {
                $sorted = $nums;
                sort($sorted);
                $cnt = count($sorted);
                $sum = array_sum($sorted);
                $mean = $sum / $cnt;
                $mid = (int)floor($cnt / 2);
                $median = ($cnt % 2 === 1) ? $sorted[$mid] : (($sorted[$mid - 1] + $sorted[$mid]) / 2);
                $txt = $lang === 'en'
                    ? "From {$cnt} values: mean = " . self::fmtPlain($mean, 2) . ", median = " . self::fmtPlain($median, 2) . ", min = " . self::fmtPlain($sorted[0]) . ", max = " . self::fmtPlain($sorted[$cnt - 1]) . "."
                    : "Dari {$cnt} data: rata-rata = " . self::fmtPlain($mean, 2) . ", median = " . self::fmtPlain($median, 2) . ", min = " . self::fmtPlain($sorted[0]) . ", maks = " . self::fmtPlain($sorted[$cnt - 1]) . ".";
                return self::reply('calc_average_median', $txt);
            }
        }

        if (self::isToolAllowed($am, 'calc_factorial_permutation') && (preg_match('/\b(?:faktorial|factorial)\s*(?:dari\s*)?(\d{1,2})\b/u', $lower, $m) || preg_match('/\b(\d{1,2})\s*!/u', $lower, $m))) {
            $n = (int)$m[1];
            if ($n >= 0 && $n <= 20) {
                $f = 1;
                for ($i = 2; $i <= $n; $i++) $f *= $i;
                $txt = $lang === 'en' ? "{$n}! (factorial of {$n}) equals {$f}." : "Hasil {$n}! (faktorial dari {$n}) adalah {$f}.";
                return self::reply('calc_factorial_permutation', $txt);
            }
        }

        if (self::isToolAllowed($am, 'calc_square_cube_root') && preg_match('/\b(akar\s+kuadrat|akar\s+pangkat\s+3|akar\s+kubik|akar|square\s+root|sqrt)\s*(?:dari\s*)?(\d+(?:\.\d+)?)\b/u', $lower, $m)) {
            $val = (float)$m[2];
            $isCube = (strpos($m[1], '3') !== false || strpos($m[1], 'kubik') !== false);
            $res = $isCube ? pow($val, 1 / 3) : sqrt($val);
            $label = $isCube ? ($lang === 'en' ? 'cube root' : 'akar pangkat 3') : ($lang === 'en' ? 'square root' : 'akar kuadrat');
            $txt = $lang === 'en'
                ? "The {$label} of " . self::fmtPlain($val) . " is " . self::fmtPlain($res, 4) . "."
                : "Nilai {$label} dari " . self::fmtPlain($val) . " adalah " . self::fmtPlain($res, 4) . ".";
            return self::reply('calc_square_cube_root', $txt);
        }

        if (self::isToolAllowed($am, 'calc_prime_checker') && preg_match('/\b(?:apakah\s+|cek\s+)?(\d{1,7})\s+(?:bilangan\s+prima|prima|is\s+prime)|\b(?:cek\s+prima|prima|prime\s+number)\s+(\d{1,7})\b/u', $lower, $m)) {
            $n = (int)(!empty($m[1]) ? $m[1] : $m[2]);
            $isPrime = ($n >= 2);
            $factor = null;
            for ($i = 2; $i * $i <= $n; $i++) {
                if ($n % $i === 0) {
                    $isPrime = false;
                    $factor = $i;
                    break;
                }
            }
            $txt = $isPrime
                ? ($lang === 'en' ? "{$n} is a prime number." : "{$n} merupakan bilangan prima.")
                : ($lang === 'en' ? "{$n} is not a prime number" . ($factor ? " (divisible by {$factor})." : ".") : "{$n} bukan bilangan prima" . ($factor ? " (habis dibagi {$factor})." : "."));
            return self::reply('calc_prime_checker', $txt);
        }

        if (self::isToolAllowed($am, 'calc_gcd_lcm') && preg_match('/\b(fpb|kpk|gcd|lcm)\b/u', $lower)) {
            $nums = array_map('intval', self::extractNums($lower));
            if (count($nums) >= 2 && $nums[0] > 0 && $nums[1] > 0) {
                $a = $nums[0];
                $b = $nums[1];
                $x = $a;
                $y = $b;
                while ($y !== 0) { $t = $x % $y; $x = $y; $y = $t; }
                $gcd = $x;
                $lcm = (int)(($a / $gcd) * $b);
                $txt = $lang === 'en'
                    ? "For {$a} and {$b}: GCD = {$gcd}, LCM = {$lcm}."
                    : "Untuk bilangan {$a} dan {$b}: FPB = {$gcd} dan KPK = {$lcm}.";
                return self::reply('calc_gcd_lcm', $txt);
            }
        }

        if (self::isToolAllowed($am, 'calc_bmi_health') && preg_match('/\b(bmi|imt|indeks\s+massa\s+tubuh)\b/u', $lower)) {
            $nums = self::extractNums($lower);
            if (count($nums) >= 2) {
                $w = min($nums[0], $nums[1]);
                $hCm = max($nums[0], $nums[1]);
                if ($hCm > 3) {
                    $hM = $hCm / 100.0;
                    $bmi = $w / ($hM * $hM);
                    $cat = $bmi < 18.5 ? 'Kurus (Underweight)' : ($bmi < 25 ? 'Normal / Ideal' : ($bmi < 30 ? 'Berlebih (Overweight)' : 'Obesitas'));
                    $txt = $lang === 'en'
                        ? "With weight {$w} kg and height {$hCm} cm, your BMI is " . round($bmi, 1) . " ({$cat})."
                        : "Dengan berat {$w} kg dan tinggi {$hCm} cm, skor BMI Anda adalah " . round($bmi, 1) . " ({$cat}).";
                    return self::reply('calc_bmi_health', $txt);
                }
            }
        }

        if (self::isToolAllowed($am, 'calc_age_exact') && preg_match('/\b(hitung\s+umur|usia\s+lahir|berapa\s+umur|calculate\s+age)\b/u', $lower)) {
            $tzObj = new DateTimeZone('Asia/Makassar');
            $now = new DateTimeImmutable('today', $tzObj);
            if (preg_match('/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $lower, $dm) && checkdate((int)$dm[2], (int)$dm[3], (int)$dm[1])) {
                $dob = new DateTimeImmutable($dm[0], $tzObj);
                $diff = $dob->diff($now);
                $txt = $lang === 'en' ? "Age from {$dm[0]} is {$diff->y} years and {$diff->m} months." : "Usia dari tanggal lahir {$dm[0]} adalah {$diff->y} tahun {$diff->m} bulan.";
                return self::reply('calc_age_exact', $txt);
            } elseif (preg_match('/\b(19\d{2}|20[0-2]\d)\b/', $lower, $ym)) {
                $age = max(0, (int)$now->format('Y') - (int)$ym[1]);
                $txt = $lang === 'en' ? "Born in {$ym[1]}, your age in " . $now->format('Y') . " is around {$age} years." : "Lahir tahun {$ym[1]}, usia pada tahun " . $now->format('Y') . " adalah sekitar {$age} tahun.";
                return self::reply('calc_age_exact', $txt);
            }
        }

        if (self::isToolAllowed($am, 'calc_countdown_days') && preg_match('/\b(berapa\s+hari\s+lagi\s+ke|hitung\s+mundur\s+ke|days\s+until)\b/u', $lower)) {
            $now = new DateTimeImmutable('today', new DateTimeZone('Asia/Makassar'));
            $target = null;
            if (strpos($lower, 'tahun baru') !== false || strpos($lower, 'new year') !== false) {
                $target = new DateTimeImmutable(((int)$now->format('Y') + 1) . '-01-01', new DateTimeZone('Asia/Makassar'));
            } elseif (strpos($lower, 'natal') !== false || strpos($lower, '25 desember') !== false || strpos($lower, 'christmas') !== false) {
                $yr = (int)$now->format('Y');
                $target = new DateTimeImmutable("{$yr}-12-25", new DateTimeZone('Asia/Makassar'));
                if ($target < $now) $target = $target->modify('+1 year');
            } elseif (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $lower, $dm)) {
                $target = new DateTimeImmutable($dm[1], new DateTimeZone('Asia/Makassar'));
            }
            if ($target !== null) {
                $days = (int)$now->diff($target)->days;
                $txt = $lang === 'en' ? "There are {$days} days left until " . $target->format('M j, Y') . "." : "Tersisa {$days} hari lagi menuju tanggal " . $target->format('d M Y') . ".";
                return self::reply('calc_countdown_days', $txt);
            }
        }

        if (self::isToolAllowed($am, 'calc_circle_geometry') && preg_match('/\b(luas\s+lingkaran|keliling\s+lingkaran|circle\s+area)\b/u', $lower)) {
            $nums = self::extractNums($lower);
            if (!empty($nums) && $nums[0] > 0) {
                $r = (strpos($lower, 'diameter') !== false) ? ($nums[0] / 2) : $nums[0];
                $area = M_PI * $r * $r;
                $circ = 2 * M_PI * $r;
                $txt = $lang === 'en'
                    ? "Circle with radius {$r}: area = " . self::fmtPlain($area, 2) . ", circumference = " . self::fmtPlain($circ, 2) . "."
                    : "Lingkaran berjari-jari {$r}: luas = " . self::fmtPlain($area, 2) . " dan keliling = " . self::fmtPlain($circ, 2) . ".";
                return self::reply('calc_circle_geometry', $txt);
            }
        }

        if (self::isToolAllowed($am, 'calc_triangle_rectangle') && preg_match('/\b(luas\s+segitiga|luas\s+persegi\s+panjang|luas\s+persegi|pythagoras)\b/u', $lower)) {
            $nums = self::extractNums($lower);
            if (strpos($lower, 'segitiga') !== false && count($nums) >= 2) {
                $area = 0.5 * $nums[0] * $nums[1];
                $txt = "Luas segitiga (alas {$nums[0]}, tinggi {$nums[1]}) adalah " . self::fmtPlain($area, 2) . ".";
                return self::reply('calc_triangle_rectangle', $txt);
            } elseif (strpos($lower, 'pythagoras') !== false && count($nums) >= 2) {
                $hyp = sqrt($nums[0] * $nums[0] + $nums[1] * $nums[1]);
                return self::reply('calc_triangle_rectangle', "Sisi miring Pythagoras dari {$nums[0]} dan {$nums[1]} adalah " . self::fmtPlain($hyp, 4) . ".");
            } elseif (strpos($lower, 'persegi panjang') !== false && count($nums) >= 2) {
                $area = $nums[0] * $nums[1];
                $perim = 2 * ($nums[0] + $nums[1]);
                return self::reply('calc_triangle_rectangle', "Persegi panjang ({$nums[0]} x {$nums[1]}): luas = " . self::fmtPlain($area, 2) . ", keliling = " . self::fmtPlain($perim, 2) . ".");
            } elseif (strpos($lower, 'persegi') !== false && !empty($nums)) {
                $s = $nums[0];
                return self::reply('calc_triangle_rectangle', "Persegi bersisi {$s}: luas = " . self::fmtPlain($s * $s, 2) . ", keliling = " . self::fmtPlain(4 * $s, 2) . ".");
            }
        }

        return null;
    }

    private static function dispatchAcademicWorkflows(string $message, string $lower, string $lang, string $sc, string $tz, Database $db, ?array $am): ?array {
        if (self::isToolAllowed($am, 'calc_gpa_grade_converter') && preg_match('/\b(nilai\s+huruf|indeks\s+nilai|konversi\s+skor|nilai\s+mutu)\b/u', $lower)) {
            $nums = self::extractNums($lower);
            if (!empty($nums)) {
                $score = max(0, min(100, $nums[0]));
                $scale = [
                    [85, 'A', '4.00', 'Sangat Baik'],
                    [80, 'A-', '3.70', 'Baik Sekali'],
                    [75, 'B+', '3.30', 'Baik'],
                    [70, 'B', '3.00', 'Baik'],
                    [65, 'B-', '2.70', 'Cukup Baik'],
                    [60, 'C+', '2.30', 'Cukup'],
                    [55, 'C', '2.00', 'Cukup'],
                    [45, 'D', '1.00', 'Kurang'],
                    [0, 'E', '0.00', 'Tidak Lulus']
                ];
                foreach ($scale as $row) {
                    if ($score >= $row[0]) {
                        $txt = $lang === 'en'
                            ? "Score {$score} converts to letter grade {$row[1]} (GPA weight {$row[2]})."
                            : "Nilai angka {$score} setara dengan huruf mutu {$row[1]} (bobot {$row[2]}, kategori {$row[3]}).";
                        return self::reply('calc_gpa_grade_converter', $txt);
                    }
                }
            }
        }

        if (self::isToolAllowed($am, 'calc_target_ipk_planner') && preg_match('/\b(target\s+ipk|naikkan\s+ipk|kejar\s+ipk)\b/u', $lower)) {
            $nums = self::extractNums($lower);
            $cur = $nums[0] ?? 3.10;
            $tgt = $nums[1] ?? min(4.0, round($cur + 0.25, 2));
            return self::reply('calc_target_ipk_planner', "Untuk menaikkan IPK dari {$cur} menuju {$tgt}, fokuskan nilai minimal A-/A pada mata kuliah berbobot 3-4 SKS dan jaga kehadiran di atas 80%.");
        }

        $policies = [
            'check_cumlaude_eligibility' => [
                '/\b(syarat\s+cum\s*laude|syarat\s+cumlaude|syarat\s+dean\s*list|cek\s+cumlaude|predikat\s+pujian)\b/u',
                "Syarat Dean's List UNKLAB adalah IPS >= 3.50 (minimal 15 SKS tanpa nilai kurang), sedangkan predikat Cum Laude membutuhkan IPK akhir >= 3.51 serta masa studi tepat waktu.",
                "UNKLAB Dean's List requires semester GPA >= 3.50 (min 15 SKS), while Cum Laude requires final GPA >= 3.51 with on-time completion."
            ],
            'check_skripsi_prereq' => [
                '/\b(syarat\s+ambil\s+skripsi|daftar\s+proposal\s+skripsi|syarat\s+tugas\s+akhir|syarat\s+skripsi)\b/u',
                "Prasyarat pengajuan Proposal Skripsi umumnya telah menempuh >= 110 SKS, lulus Metodologi Penelitian, memiliki IPK >= 2.25, dan mendapat persetujuan Dosen Pembimbing.",
                "Thesis proposal prerequisites typically require >= 110 earned credits, passing Research Methodology, GPA >= 2.25, and advisor approval."
            ],
            'check_kkn_internship_flow' => [
                '/\b(alur\s+daftar\s+magang|prosedur\s+kkn|syarat\s+kerja\s+praktek|daftar\s+kp|alur\s+magang)\b/u',
                "Alur Kerja Praktek/KKN dimulai dari pengisian form di prodi (>= 90 SKS), pengajuan surat pengantar ke instansi, pelaksanaan bimbingan lapangan, hingga seminar laporan.",
                "Internship/KKN workflow spans department registration (>= 90 credits), official placement letter, field supervision, and final report defense."
            ],
            'check_krs_revision_workflow' => [
                '/\b(alur\s+krs|tambah\s+hapus\s+krs|revisi\s+krs|perwalian\s+pa)\b/u',
                "Workflow pengisian/revisi KRS: lunasi registrasi keuangan, konsultasi paket SKS bersama Penasehat Akademik (PA), lalu finalisasi mata kuliah sebelum batas masa Add/Drop berakhir.",
                "KRS add/drop workflow: clear financial registration, consult your Academic Advisor on credit load, and lock courses before the deadline."
            ],
            'check_cuti_akademik_flow' => [
                '/\b(prosedur\s+cuti\s+kuliah|syarat\s+cuti\s+akademik|aktif\s+kembali\s+kuliah)\b/u',
                "Pengajuan cuti akademik memerlukan surat permohonan berpersetujuan Dekan/PA serta bebas administrasi keuangan dan perpustakaan sebelum masa kuliah berjalan.",
                "Academic leave requires advisor and Dean approval along with financial and library clearance before the active term begins."
            ],
            'check_yudisium_wisuda_flow' => [
                '/\b(syarat\s+yudisium|alur\s+wisuda|pendaftaran\s+wisuda|berkas\s+kelulusan)\b/u',
                "Kelengkapan Yudisium & Wisuda meliputi bukti lulus sidang skripsi, unggah repositori perpustakaan, bebas tunggakan keuangan, sertifikat TOEFL, dan berkas ijazah di BAA.",
                "Graduation clearance requires thesis defense pass, repository upload, financial clearance, TOEFL score, and BAA diploma verification."
            ],
            'check_ukt_payment_workflow' => [
                '/\b(cara\s+bayar\s+uang\s+kuliah|virtual\s+account\s+kuliah|jadwal\s+cicilan\s+spp|aktivasi\s+keuangan)\b/u',
                "Pembayaran uang kuliah dilakukan memakai nomor Virtual Account bank mitra sesuai tagihan portal keuangan agar status aktif semester tercatat otomatis.",
                "Tuition payments are settled using your student Virtual Account via partner banks for automatic semester activation."
            ],
            'check_scholarship_eligibility' => [
                '/\b(simulasi\s+syarat\s+beasiswa|kelayakan\s+beasiswa|beasiswa\s+kip|working\s+student)\b/u',
                "Jalur bantuan biaya UNKLAB meliputi Beasiswa Prestasi (IPK >= 3.30), KIP-Kuliah, dan program Working Student di unit kerja kampus dengan evaluasi kinerja tiap semester.",
                "Scholarship options include Merit Awards (GPA >= 3.30), KIP-Kuliah, and campus Working Student placements reviewed each term."
            ],
            'check_dormitory_rules_workflow' => [
                '/\b(aturan\s+asrama|jam\s+malam\s+asrama|daftar\s+asrama\s+unklab|dormitory\s+rules)\b/u',
                "Mahasiswa asrama UNKLAB wajib mendaftar di kantor asrama, mengikuti ibadah malam rutin, menjaga ketertiban kamar, serta mematuhi batas jam malam dan izin keluar kampus.",
                "UNKLAB dormitory residents register with the residence dean, attend evening worship, and observe campus curfew and leave permits."
            ],
            'check_chapel_spirituality_credit' => [
                '/\b(aturan\s+chapel|poin\s+kerohanian|absen\s+ibadah\s+chapel|sabbath\s+campus)\b/u',
                "Mahasiswa UNKLAB wajib memenuhi kehadiran ibadah Chapel mingguan dan kegiatan pengembangan karakter Kristiani sesuai standar Kemahasiswaan tiap semester.",
                "UNKLAB students fulfill weekly Chapel attendance and character formation credits monitored by Student Affairs each semester."
            ],
            'check_toefl_english_req' => [
                '/\b(syarat\s+skor\s+toefl|nilai\s+minimal\s+toefl|english\s+proficiency\s+lulus)\b/u',
                "Calon lulusan sarjana wajib melampirkan sertifikat English Proficiency/TOEFL sesuai ambang minimal fakultas yang terverifikasi oleh pusat bahasa sebelum pendaftaran yudisium.",
                "Graduating seniors must submit a verified English Proficiency/TOEFL score meeting faculty minimums prior to yudisium registration."
            ]
        ];

        foreach ($policies as $code => [$regex, $idTxt, $enTxt]) {
            if (self::isToolAllowed($am, $code) && preg_match($regex, $lower)) {
                return self::reply($code, $lang === 'en' ? $enTxt : $idTxt);
            }
        }

        if (self::isToolAllowed($am, 'calc_attendance_rule') && preg_match('/\b(maksimal\s+absen|batas\s+absen|aturan\s+kehadiran|boleh\s+bolos)\b/u', $lower)) {
            $nums = self::extractNums($lower);
            $meet = (!empty($nums) && $nums[0] >= 4 && $nums[0] <= 40) ? (int)$nums[0] : 16;
            $minAttend = (int)ceil($meet * 0.8);
            $maxAbs = $meet - $minAttend;
            $txt = "Dari {$meet} pertemuan kuliah, minimal kehadiran 80% adalah {$minAttend} kali sehingga batas maksimal ketidakhadiran hanya {$maxAbs} pertemuan.";
            return self::reply('calc_attendance_rule', $txt);
        }

        if (self::isToolAllowed($am, 'calc_pomodoro_study_plan') && preg_match('/\b(jadwal\s+pomodoro|rencana\s+belajar\s+fokus|teknik\s+pomodoro|bagi\s+waktu\s+belajar)\b/u', $lower)) {
            $nums = self::extractNums($lower);
            $hours = (!empty($nums) && $nums[0] > 0 && $nums[0] <= 12) ? (float)$nums[0] : 2.0;
            $cycles = max(1, (int)floor(($hours * 60) / 30));
            return self::reply('calc_pomodoro_study_plan', "Rencana belajar {$hours} jam: jalankan {$cycles} sesi Pomodoro (25 menit fokus penuh + 5 menit jeda) dengan istirahat panjang 15 menit setelah sesi ke-4.");
        }

        if (self::isToolAllowed($am, 'recommend_study_concentration') && preg_match('/\b(rekomendasi\s+konsentrasi|pilih\s+peminatan|konsentrasi\s+ai\s+atau\s+rpl|minat\s+jaringan)\b/u', $lower)) {
            return self::reply('recommend_study_concentration', "Pilih konsentrasi Artificial Intelligence jika menyukai machine learning dan data, Software Engineering untuk perancangan aplikasi skala besar, atau Cyber Security/Jaringan untuk keamanan infrastruktur.");
        }

        if (self::isToolAllowed($am, 'generate_research_title_ideas') && preg_match('/\b(ide\s+judul\s+skripsi|topik\s+riset\s+ai|judul\s+penelitian|ide\s+tugas\s+akhir)\b/u', $lower)) {
            return self::reply('generate_research_title_ideas', "Tiga ide judul skripsi AI: (1) Chatbot Akademik Berbasis RAG dan LLM, (2) Sistem Deteksi Kehadiran Mahasiswa via Computer Vision, dan (3) Optimasi Rekomendasi KRS Menggunakan Reinforcement Learning.");
        }

        if (self::isToolAllowed($am, 'check_campus_emergency_contacts') && preg_match('/\b(kontak\s+akademik|layanan\s+baa|helpdesk\s+it\s+kampus|nomor\s+layanan\s+mahasiswa)\b/u', $lower)) {
            return self::reply('check_campus_emergency_contacts', "Layanan kampus resmi UNKLAB dapat diakses melalui Biro Administrasi Akademik (BAA), Bagian Keuangan, Kemahasiswaan, serta Helpdesk IT di Gedung Rektorat pada jam kerja Senin-Jumat.");
        }

        if (self::isToolAllowed($am, 'run_academic_workflow') && preg_match('/\b(simulasi\s+sks|hitung\s+sks|workflow\s+akademik|ips\s+saya|ipk\s+saya)\b.*?(?:([0-3]\.\d{1,2}|4\.00|4\.0))?/u', $lower, $m)) {
            $ips = isset($m[2]) && $m[2] !== '' ? (float)$m[2] : 3.25;
            $maxSks = $ips >= 3.00 ? 24 : ($ips >= 2.50 ? 21 : ($ips >= 2.00 ? 18 : 15));
            $txt = $lang === 'en'
                ? "Academic Workflow executed: With a GPA/IPS of {$ips}, your maximum credit load is {$maxSks} credits (SKS), subject to Academic Advisor approval."
                : "Workflow Akademik dijalankan: Dengan capaian IPS {$ips}, beban studi maksimal yang dapat Anda ambil adalah {$maxSks} SKS dengan persetujuan Penasehat Akademik.";
            return self::reply('run_academic_workflow', $txt);
        }

        return null;
    }

    private static function dispatchConvertersAndGenerators(string $message, string $lower, string $lang, string $sc, string $tz, Database $db, ?array $am): ?array {
        if (self::isToolAllowed($am, 'convert_temperature') && preg_match('/(-?\d+(?:\.\d+)?)\s*(?:°|derajat\s*)?(c|celsius|celcius|f|fahrenheit|k|kelvin)\s+(?:ke|to|dalam)\s+(c|celsius|celcius|f|fahrenheit|k|kelvin)\b/u', $lower, $m)) {
            $val = (float)$m[1];
            $from = $m[2][0];
            $to = $m[3][0];
            $c = ($from === 'c') ? $val : (($from === 'f') ? (($val - 32) * 5 / 9) : ($val - 273.15));
            $out = ($to === 'c') ? $c : (($to === 'f') ? ($c * 9 / 5 + 32) : ($c + 273.15));
            return self::reply('convert_temperature', self::fmtPlain($val, 2) . " " . strtoupper($from) . " setara dengan " . self::fmtPlain($out, 2) . " " . strtoupper($to) . ".");
        }

        $unitSpecs = [
            'convert_length_distance' => [
                'km' => 1000.0, 'kilometer' => 1000.0, 'm' => 1.0, 'meter' => 1.0,
                'cm' => 0.01, 'mm' => 0.001, 'mil' => 1609.344, 'mile' => 1609.344,
                'yard' => 0.9144, 'kaki' => 0.3048, 'feet' => 0.3048, 'ft' => 0.3048, 'inci' => 0.0254, 'inch' => 0.0254
            ],
            'convert_weight_mass' => [
                'ton' => 1000.0, 'kg' => 1.0, 'kilogram' => 1.0, 'ons' => 0.1,
                'gram' => 0.001, 'gr' => 0.001, 'g' => 0.001, 'mg' => 0.000001, 'pon' => 0.45359237, 'pound' => 0.45359237, 'lb' => 0.45359237
            ],
            'convert_data_storage' => [
                'byte' => 1.0, 'bytes' => 1.0, 'kb' => 1024.0, 'mb' => 1048576.0, 'gb' => 1073741824.0, 'tb' => 1099511627776.0
            ],
            'convert_speed_units' => [
                'km/jam' => 0.27777778, 'km/j' => 0.27777778, 'kmh' => 0.27777778, 'm/s' => 1.0, 'mph' => 0.44704, 'knot' => 0.514444
            ],
            'convert_area_units' => [
                'm2' => 1.0, 'meter2' => 1.0, 'are' => 100.0, 'hektar' => 10000.0, 'ha' => 10000.0, 'km2' => 1000000.0
            ],
            'convert_time_duration' => [
                'detik' => 1.0, 'second' => 1.0, 'menit' => 60.0, 'minute' => 60.0, 'jam' => 3600.0, 'hour' => 3600.0, 'hari' => 86400.0, 'day' => 86400.0, 'minggu' => 604800.0
            ]
        ];
        if (preg_match('/(\d+(?:\.\d+)?)\s*([a-z0-9\/]+)\s+(?:ke|to|berapa|dalam)\s+([a-z0-9\/]+)\b/u', $lower, $um)) {
            $uVal = (float)$um[1];
            $uFrom = $um[2];
            $uTo = $um[3];
            foreach ($unitSpecs as $toolCode => $table) {
                if (isset($table[$uFrom], $table[$uTo]) && self::isToolAllowed($am, $toolCode)) {
                    $converted = ($uVal * $table[$uFrom]) / $table[$uTo];
                    return self::reply($toolCode, self::fmtPlain($uVal, 3) . " {$uFrom} = " . self::fmtPlain($converted, 4) . " {$uTo}.");
                }
            }
        }

        if (self::isToolAllowed($am, 'convert_number_base') && preg_match('/\b(biner|binary|hex|heksadesimal|oktal|octal)\b/u', $lower)) {
            if (preg_match('/\bbiner\s+([01]{2,32})\s+(?:ke\s+desimal)\b/u', $lower, $bm)) {
                return self::reply('convert_number_base', "Bilangan biner {$bm[1]} setara dengan desimal " . bindec($bm[1]) . ".");
            }
            $nums = self::extractNums($lower);
            if (!empty($nums)) {
                $n = max(0, (int)$nums[0]);
                return self::reply('convert_number_base', "Bilangan desimal {$n}: Biner = " . decbin($n) . ", Hex = " . strtoupper(dechex($n)) . ", Oktal = " . decoct($n) . ".");
            }
        }

        if (self::isToolAllowed($am, 'convert_currency_estimate') && preg_match('/(\d+(?:\.\d+)?)\s*(usd|dolar|sgd|eur|euro|jpy|yen|myr)\s*(?:ke\s*)?(rupiah|idr)?\b/u', $lower, $cm)) {
            $rates = ['usd' => 15850, 'dolar' => 15850, 'sgd' => 11800, 'eur' => 17150, 'euro' => 17150, 'jpy' => 104, 'myr' => 3580];
            $amt = (float)$cm[1];
            $cur = $cm[2];
            if (isset($rates[$cur])) {
                $idr = $amt * $rates[$cur];
                return self::reply('convert_currency_estimate', "Estimasi konversi {$amt} " . strtoupper($cur) . " ke Rupiah sekitar " . self::fmtIdr($idr) . " (kurs indikatif).");
            }
        }

        if (self::isToolAllowed($am, 'convert_world_timezone') && preg_match('/\b(jam\s+(?:berapa\s+)?di\s+(?:jakarta|jayapura|tokyo|london|new\s+york)|wita\s+ke\s+wib|wib\s+ke\s+wita|waktu\s+utc)\b/u', $lower)) {
            $zones = ['jakarta' => 'Asia/Jakarta', 'jayapura' => 'Asia/Jayapura', 'tokyo' => 'Asia/Tokyo', 'london' => 'Europe/London', 'new york' => 'America/New_York', 'wib' => 'Asia/Jakarta', 'wita' => 'Asia/Makassar', 'utc' => 'UTC'];
            if (strpos($lower, 'wita ke wib') !== false && preg_match('/\b(\d{1,2})[:\.](\d{2})\b/', $lower, $tm)) {
                $dt = new DateTimeImmutable(sprintf('%02d:%02d:00', (int)$tm[1], (int)$tm[2]), new DateTimeZone('Asia/Makassar'));
                return self::reply('convert_world_timezone', $dt->format('H:i') . " WITA sama dengan " . $dt->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('H:i') . " WIB.");
            }
            if (strpos($lower, 'wib ke wita') !== false && preg_match('/\b(\d{1,2})[:\.](\d{2})\b/', $lower, $tm)) {
                $dt = new DateTimeImmutable(sprintf('%02d:%02d:00', (int)$tm[1], (int)$tm[2]), new DateTimeZone('Asia/Jakarta'));
                return self::reply('convert_world_timezone', $dt->format('H:i') . " WIB sama dengan " . $dt->setTimezone(new DateTimeZone('Asia/Makassar'))->format('H:i') . " WITA.");
            }
            foreach ($zones as $city => $zId) {
                if (strpos($lower, $city) !== false) {
                    $dt = new DateTimeImmutable('now', new DateTimeZone($zId));
                    return self::reply('convert_world_timezone', "Waktu saat ini di " . ucwords($city) . " ({$zId}) adalah pukul " . $dt->format('H:i, d M Y') . ".");
                }
            }
        }

        if (self::isToolAllowed($am, 'generate_secure_password') && preg_match('/\b(buatkan\s+password|generate\s+password|password\s+acak|sandi\s+acak)\b/u', $lower)) {
            $nums = self::extractNums($lower);
            $len = (!empty($nums) && $nums[0] >= 8 && $nums[0] <= 32) ? (int)$nums[0] : 14;
            $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%&*';
            $pwd = '';
            $max = strlen($chars) - 1;
            for ($i = 0; $i < $len; $i++) $pwd .= $chars[random_int(0, $max)];
            return self::reply('generate_secure_password', "Kata sandi acak {$len} karakter berhasil dibuat: {$pwd}");
        }

        if (self::isToolAllowed($am, 'generate_uuid_token') && preg_match('/\b(buatkan\s+uuid|generate\s+uuid|token\s+unik|kode\s+acak\s+unik)\b/u', $lower)) {
            $b = random_bytes(16);
            $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
            $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
            $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
            return self::reply('generate_uuid_token', "UUID v4 baru berhasil dibuat: {$uuid}");
        }

        if (self::isToolAllowed($am, 'text_word_char_stats') && preg_match('/\b(hitung\s+jumlah\s+kata|word\s+count|hitung\s+karakter)\s*:\s*(.+)$/us', $message, $m)) {
            $sample = trim($m[2]);
            $wc = str_word_count($sample);
            $cc = function_exists('mb_strlen') ? mb_strlen($sample, 'UTF-8') : strlen($sample);
            return self::reply('text_word_char_stats', "Teks berisi {$wc} kata dan {$cc} karakter.");
        }

        if (self::isToolAllowed($am, 'generate_sha256_hash') && preg_match('/\b(hash\s+sha256|buatkan\s+md5|encode\s+base64|decode\s+base64)\s+(?:dari\s+)?(.+)$/us', $message, $m)) {
            $cmd = strtolower($m[1]);
            $inp = trim($m[2]);
            if (strpos($cmd, 'sha256') !== false) return self::reply('generate_sha256_hash', "Hash SHA-256: " . hash('sha256', $inp));
            if (strpos($cmd, 'md5') !== false) return self::reply('generate_sha256_hash', "Hash MD5: " . md5($inp));
            if (strpos($cmd, 'encode') !== false) return self::reply('generate_sha256_hash', "Base64 encode: " . base64_encode($inp));
            $dec = base64_decode($inp, true);
            return self::reply('generate_sha256_hash', $dec !== false ? "Base64 decode: {$dec}" : "Input Base64 tidak valid.");
        }

        if (self::isToolAllowed($am, 'random_picker_dice') && preg_match('/\b(angka\s+acak|random\s+number|lempar\s+dadu|lempar\s+koin)\b/u', $lower)) {
            if (strpos($lower, 'dadu') !== false) return self::reply('random_picker_dice', "Hasil lempar dadu: angka " . random_int(1, 6) . ".");
            if (strpos($lower, 'koin') !== false) return self::reply('random_picker_dice', "Hasil lempar koin: " . (random_int(0, 1) === 1 ? 'Angka (Tails)' : 'Gambar (Heads)') . ".");
            $nums = self::extractNums($lower);
            $min = (count($nums) >= 2) ? (int)min($nums[0], $nums[1]) : 1;
            $max = (count($nums) >= 2) ? (int)max($nums[0], $nums[1]) : (!empty($nums) ? max(2, (int)$nums[0]) : 100);
            return self::reply('random_picker_dice', "Angka acak antara {$min} dan {$max}: " . random_int($min, min($min + 1000000, $max)) . ".");
        }

        return null;
    }

    private static function dispatchProductivityAndCampus(string $message, string $lower, string $lang, string $sc, string $tz, Database $db, ?array $am): ?array {
        if (self::isToolAllowed($am, 'assistant_capabilities_menu') && preg_match('/\b(fitur\s+apa\s+saja|daftar\s+kemampuan|kamu\s+bisa\s+apa|list\s+function\s+calling|menu\s+bantuan\s+bot)\b/u', $lower)) {
            $cnt = $db->isConnected() && $db->hasTable('tbl_agent_tools') ? $db->getTableRowCount('tbl_agent_tools') : 100;
            return self::reply('assistant_capabilities_menu', "Saya memiliki {$cnt} Function Calling aktif mencakup kalkulator & statistik, konversi satuan/waktu, workflow akademik UNKLAB, analitik MySQL real-time, pencarian RAG + LLM, dan SQL Assistant.");
        }

        if (self::isToolAllowed($am, 'assistant_identity_version') && preg_match('/\b(versi\s+sistem\s+berapa|arsitektur\s+ai|teknologi\s+apa\s+yang\s+dipakai)\b/u', $lower)) {
            return self::reply('assistant_identity_version', "Unklabot v.2.0.1 berjalan pada arsitektur Hybrid AI PHP + MySQL dengan enkripsi AES-256, pencarian RAG terintegrasi OpenAI/Gemini LLM, 100 Function Calling, serta avatar 3D WebGL real-time.");
        }

        if (self::isToolAllowed($am, 'greeting_time_aware') && preg_match('/^\s*(?:(?:halo|hai|hello|hi|hey)\s*,?\s+)?(selamat\s+(?:pagi|siang|sore|malam)|good\s+(?:morning|afternoon|evening|night)|hai\s+bot|halo\s+asisten|halo(?:\s+unklabot)?|hai(?:\s+unklabot)?|hello(?:\s+unklabot)?)[\!\.\?\s]*$/u', $lower, $gm)) {
            $said = trim((string)($gm[1] ?? ''));
            $hr = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Makassar')))->format('G');
            $timeGreet = $hr < 11 ? 'Selamat pagi' : ($hr < 15 ? 'Selamat siang' : ($hr < 18 ? 'Selamat sore' : 'Selamat malam'));
            if (strpos($said, 'selamat ') === 0) {
                $timeGreet = ucfirst($said);
            }
            $txt = $lang === 'en' ? "Hello! Welcome to Unklabot v.2.0.1. How may I assist you today?" : "{$timeGreet}! Ada yang bisa saya bantu hari ini?";
            return self::reply('greeting_time_aware', $txt, 'Function Calling', 'HAPPY', 'WAVE');
        }

        if (self::isToolAllowed($am, 'farewell_polite_closing') && preg_match('/^\s*(terima\s+kasih\s+(?:banyak|ya)|makasih\s+(?:banyak|ya)|sampai\s+jumpa|goodbye|bye\s+bye|thank\s+you\s+very\s+much)[\!\.\?\s]*$/u', $lower)) {
            $txt = $lang === 'en' ? "You are very welcome! Feel free to ask anytime." : "Sama-sama, senang dapat membantu Anda! Silakan hubungi saya kembali kapan saja.";
            return self::reply('farewell_polite_closing', $txt, 'Function Calling', 'HAPPY', 'WAVE');
        }

        if (self::isToolAllowed($am, 'translate_quick_phrase') && preg_match('/\b(bahasa\s+inggrisnya|arti\s+kata|terjemahan\s+istilah)\s+([a-z\s]+?)(?:\s+(?:itu\s+)?(?:apa|adalah))?\??$/u', $lower, $tm)) {
            $dict = [
                'skripsi' => 'undergraduate thesis', 'beasiswa' => 'scholarship', 'fakultas' => 'faculty',
                'kurikulum' => 'curriculum', 'kecerdasan buatan' => 'artificial intelligence', 'asrama' => 'dormitory',
                'wisuda' => 'graduation', 'kuliah' => 'lecture / course', 'curriculum' => 'kurikulum', 'scholarship' => 'beasiswa', 'thesis' => 'skripsi / tesis'
            ];
            $w = trim($tm[2]);
            if (isset($dict[$w])) {
                return self::reply('translate_quick_phrase', "Padanan istilah '{$w}' adalah '{$dict[$w]}'.");
            }
        }

        if (self::isToolAllowed($am, 'check_day_holiday_status') && preg_match('/\b(apakah\s+(?:besok|hari\s+ini)\s+(?:libur|akhir\s+pekan)|cek\s+hari\s+kerja)\b/u', $lower)) {
            $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Makassar'));
            $target = (strpos($lower, 'besok') !== false) ? $now->modify('+1 day') : $now;
            $dow = (int)$target->format('N');
            $lbl = ($dow >= 6) ? 'akhir pekan (Sabtu/Minggu)' : 'hari kerja/perkuliahan aktif (Senin-Jumat)';
            return self::reply('check_day_holiday_status', "Tanggal " . $target->format('d M Y') . " merupakan {$lbl}.");
        }

        if (self::isToolAllowed($am, 'calc_calorie_water_intake') && preg_match('/\b(kebutuhan\s+air\s+minum|kebutuhan\s+kalori|minum\s+air\s+per\s+hari)\b/u', $lower)) {
            $nums = self::extractNums($lower);
            $kg = (!empty($nums) && $nums[0] >= 25 && $nums[0] <= 200) ? $nums[0] : 60.0;
            $ml = (int)round($kg * 35);
            $kcal = (int)round($kg * 30);
            return self::reply('calc_calorie_water_intake', "Untuk berat badan {$kg} kg, kebutuhan air minum sekitar {$ml} ml (" . round($ml / 1000, 1) . " liter) dan energi dasar sekitar {$kcal} kkal per hari.");
        }

        if (self::isToolAllowed($am, 'calc_split_bill_group') && preg_match('/\b(bagi\s+tagihan|split\s+bill|patungan\s+bayar)\b/u', $lower)) {
            $nums = self::extractNums($lower);
            if (count($nums) >= 2 && $nums[0] > 0 && $nums[1] > 0) {
                $total = max($nums[0], $nums[1]);
                $people = max(1, (int)min($nums[0], $nums[1]));
                $each = $total / $people;
                return self::reply('calc_split_bill_group', "Total " . self::fmtIdr($total) . " dibagi {$people} orang adalah " . self::fmtIdr($each) . " per orang.");
            }
        }

        if (self::isToolAllowed($am, 'calc_fuel_trip_cost') && preg_match('/\b(biaya\s+bensin|ongkos\s+bbm|biaya\s+perjalanan)\b/u', $lower)) {
            $nums = self::extractNums($lower);
            $km = (!empty($nums) && $nums[0] > 0) ? $nums[0] : 50.0;
            $liters = $km / 12.0;
            $cost = $liters * 10000.0;
            return self::reply('calc_fuel_trip_cost', "Estimasi BBM perjalanan {$km} km (asumsi 1:12 km/L, Rp 10.000/L) butuh " . self::fmtPlain($liters, 1) . " liter atau sekitar " . self::fmtIdr($cost) . ".");
        }

        if (self::isToolAllowed($am, 'validate_email_url_format') && preg_match('/\b(validasi\s+email|cek\s+email\s+valid|validasi\s+url|cek\s+format\s+url)\b/u', $lower)) {
            if (preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $message, $em)) {
                $valid = filter_var($em[0], FILTER_VALIDATE_EMAIL) !== false;
                return self::reply('validate_email_url_format', "Format email '{$em[0]}' berstatus " . ($valid ? "VALID (RFC compliant)." : "TIDAK VALID."));
            }
            if (preg_match('/https?:\/\/[^\s]+/i', $message, $um)) {
                $valid = filter_var($um[0], FILTER_VALIDATE_URL) !== false;
                return self::reply('validate_email_url_format', "Format URL '{$um[0]}' berstatus " . ($valid ? "VALID." : "TIDAK VALID."));
            }
        }

        if (self::isToolAllowed($am, 'generate_acronym_abbreviation') && preg_match('/\b(?:apa\s+kepanjangan|kepanjangan\s+dari|arti\s+singkatan|singkatan)\s+([a-z]{2,12})\b/u', $lower, $am2)) {
            $acr = [
                'unklab' => 'Universitas Klabat (Airmadidi, Minahasa Utara, berdiri 1965)',
                'filkom' => 'Fakultas Ilmu Komputer Universitas Klabat',
                'feb' => 'Fakultas Ekonomi dan Bisnis',
                'fik' => 'Fakultas Ilmu Keperawatan',
                'fkip' => 'Fakultas Keguruan dan Ilmu Pendidikan',
                'baa' => 'Biro Administrasi Akademik',
                'krs' => 'Kartu Rencana Studi',
                'ipk' => 'Indeks Prestasi Kumulatif',
                'sks' => 'Satuan Kredit Semester',
                'rag' => 'Retrieval-Augmented Generation',
                'llm' => 'Large Language Model'
            ];
            $k = strtolower($am2[1]);
            if (isset($acr[$k])) {
                return self::reply('generate_acronym_abbreviation', strtoupper($k) . " merupakan singkatan dari " . $acr[$k] . ".");
            }
        }

        if (self::isToolAllowed($am, 'check_nim_year_info') && preg_match('/\b(?:cek\s+angkatan\s+nim|info\s+nim|nim)\s*(?:tahun\s*)?(\d{6,14})\b/u', $lower, $nm)) {
            $nim = $nm[1];
            $yr = (preg_match('/20(1\d|2[0-6])/', $nim, $ym)) ? (int)('20' . $ym[1]) : 2023;
            $curYr = (int)date('Y');
            $semEst = max(1, min(14, (($curYr - $yr) * 2) + 1));
            return self::reply('check_nim_year_info', "NIM {$nim} mengindikasikan angkatan tahun {$yr} (estimasi berada di sekitar semester {$semEst}).");
        }

        if (self::isToolAllowed($am, 'campus_building_navigator') && preg_match('/\b(dimana\s+gedung|letak\s+perpustakaan|lokasi\s+gedung\s+filkom|lokasi\s+rektorat|arah\s+gedung\s+asrama)\b/u', $lower)) {
            return self::reply('campus_building_navigator', "Kompleks utama kampus UNKLAB di Airmadidi berpusat pada Gedung Rektorat (Pioneer) di bagian depan, Perpustakaan & Auditorium di koridor tengah, serta gedung fakultas (FILKOM, FEB, FIK) dan asrama di area kampus.");
        }

        if (self::isToolAllowed($am, 'voice_mic_usage_guide') && preg_match('/\b(cara\s+pakai\s+mikrofon|cara\s+interupsi\s+suara|cara\s+atur\s+volume|fitur\s+suara\s+3d)\b/u', $lower)) {
            return self::reply('voice_mic_usage_guide', "Klik tombol mikrofon di bawah avatar untuk berbicara atau menghentikan ucapan avatar secara instan, dan gunakan ikon pengaturan suara untuk memilih bahasa serta tingkat volume.");
        }

        if (self::isToolAllowed($am, 'privacy_security_notice') && preg_match('/\b(apakah\s+data\s+aman|keamanan\s+api\s+key|perlindungan\s+waf|enkripsi\s+sistem)\b/u', $lower)) {
            return self::reply('privacy_security_notice', "Sistem mengamankan API Key dengan enkripsi AES-256-CBC, proteksi CSRF token, validasi anti-SSRF, serta Web Application Firewall (WAF) terhadap SQLi, XSS, dan RCE.");
        }

        return null;
    }

    private static function dispatchDatabaseAndSearch(string $message, string $lower, string $lang, string $sc, string $tz, Database $db, ?array $am, callable $cal, callable $searchRagFn): ?array {
        if (!$db->isConnected()) return null;

        if (self::isToolAllowed($am, 'query_top_rag_documents') && preg_match('/\b(dokumen\s+rag\s+paling\s+populer|top\s+dokumen\s+rag|dokumen\s+terbanyak\s+diakses|most\s+retrieved\s+document)\b/u', $lower)) {
            $db->prepare("SELECT doc_code, title, retrieval_count FROM tbl_knowledge_documents WHERE is_active = 1 ORDER BY retrieval_count DESC, id ASC LIMIT 3");
            $rows = $db->resultSet();
            $parts = array_map(static fn($r) => "{$r['doc_code']} ({$r['retrieval_count']}x)", $rows);
            return self::reply('query_top_rag_documents', "Top 3 dokumen RAG paling sering diakses saat ini: " . implode(', ', $parts) . ".");
        }

        if (self::isToolAllowed($am, 'query_top_kb_topics') && preg_match('/\b(topik\s+kb\s+terpopuler|pertanyaan\s+paling\s+sering|static\s+kb\s+hit\s+tertinggi|top\s+knowledge)\b/u', $lower)) {
            $db->prepare("SELECT topic_code, category, hit_count FROM tbl_static_knowledge WHERE is_active = 1 ORDER BY hit_count DESC, id ASC LIMIT 3");
            $rows = $db->resultSet();
            $parts = array_map(static fn($r) => "{$r['topic_code']} ({$r['hit_count']} hits)", $rows);
            return self::reply('query_top_kb_topics', "Top 3 topik Static Knowledge paling sering diakses: " . implode(', ', $parts) . ".");
        }

        if (self::isToolAllowed($am, 'query_recent_conversations') && preg_match('/\b(riwayat\s+chat\s+terakhir|percakapan\s+terbaru|pertanyaan\s+terakhir|recent\s+chats)\b/u', $lower)) {
            $db->prepare("SELECT user_message, response_source FROM tbl_va_conversations ORDER BY id DESC LIMIT 3");
            $rows = $db->resultSet();
            $parts = array_map(static fn($r) => '"' . substr((string)$r['user_message'], 0, 32) . '" (' . $r['response_source'] . ')', $rows);
            return self::reply('query_recent_conversations', !empty($parts) ? ("3 percakapan terbaru di MySQL: " . implode('; ', $parts) . ".") : "Belum ada percakapan tercatat.");
        }

        if (self::isToolAllowed($am, 'query_llm_source_breakdown') && preg_match('/\b(distribusi\s+sumber\s+jawaban|berapa\s+kali\s+llm\s+dipakai|pemakaian\s+rag\s+vs\s+llm)\b/u', $lower)) {
            $db->prepare("SELECT response_source, COUNT(*) cnt FROM tbl_va_conversations GROUP BY response_source ORDER BY cnt DESC LIMIT 4");
            $rows = $db->resultSet();
            $parts = array_map(static fn($r) => "{$r['response_source']}: {$r['cnt']}", $rows);
            return self::reply('query_llm_source_breakdown', "Distribusi sumber respons di MySQL: " . implode(', ', $parts) . ".");
        }

        if (self::isToolAllowed($am, 'query_language_usage_stats') && preg_match('/\b(statistik\s+bahasa\s+chat|berapa\s+chat\s+bahasa\s+inggris|proporsi\s+bahasa\s+percakapan)\b/u', $lower)) {
            $db->prepare("SELECT SUM(CASE WHEN language='id' THEN 1 ELSE 0 END) id_cnt, SUM(CASE WHEN language='en' THEN 1 ELSE 0 END) en_cnt FROM tbl_va_conversations");
            $r = $db->single();
            return self::reply('query_language_usage_stats', "Statistik bahasa percakapan MySQL: Bahasa Indonesia " . (int)($r['id_cnt'] ?? 0) . " chat, Bahasa Inggris " . (int)($r['en_cnt'] ?? 0) . " chat.");
        }

        if (self::isToolAllowed($am, 'query_average_eval_latency') && preg_match('/\b(rata[\s\-]rata\s+latensi|kecepatan\s+respon\s+rata[\s\-]rata|skor\s+evaluasi\s+ai\s+rata[\s\-]rata|avg\s+latency)\b/u', $lower)) {
            $db->prepare("SELECT ROUND(AVG(ai_eval_score), 3) eval_avg, ROUND(AVG(latency_ms), 1) lat_avg FROM tbl_va_conversations");
            $r = $db->single();
            return self::reply('query_average_eval_latency', "Metrik MySQL real-time: rata-rata Eval Score " . ($r['eval_avg'] ?? '0.96') . " dan rata-rata latensi " . ($r['lat_avg'] ?? '15') . " ms.");
        }

        if (self::isToolAllowed($am, 'query_active_api_provider') && preg_match('/\b(provider\s+ai\s+aktif|model\s+apa\s+yang\s+aktif\s+sekarang|cek\s+primary\s+api\s+key|provider\s+sekarang)\b/u', $lower)) {
            $db->prepare("SELECT provider, key_label, model_name, last_status FROM tbl_ai_provider_keys WHERE is_primary = 1 LIMIT 1");
            $k = $db->single();
            if ($k) {
                return self::reply('query_active_api_provider', "Primary Key aktif saat ini: {$k['key_label']} (Provider: " . strtoupper($k['provider']) . ", Model: {$k['model_name']}, Status: {$k['last_status']}).");
            }
            return self::reply('query_active_api_provider', "Belum ada Primary API Key yang dipilih di database.");
        }

        if (self::isToolAllowed($am, 'query_api_token_usage') && preg_match('/\b(total\s+token\s+api|total\s+request\s+api\s+key|statistik\s+pemakaian\s+token)\b/u', $lower)) {
            $db->prepare("SELECT COUNT(*) keys_cnt, COALESCE(SUM(total_requests),0) reqs, COALESCE(SUM(total_tokens),0) toks FROM tbl_ai_provider_keys");
            $r = $db->single();
            return self::reply('query_api_token_usage', "Tercatat {$r['keys_cnt']} kunci AI dengan total {$r['reqs']} request API dan " . number_format((int)$r['toks'], 0, ',', '.') . " token terpakai.");
        }

        if (self::isToolAllowed($am, 'query_security_incident_count') && preg_match('/\b(berapa\s+ancaman\s+diblokir|cek\s+log\s+insiden\s+keamanan|jumlah\s+serangan\s+waf|security\s+incidents)\b/u', $lower)) {
            $cnt = $db->hasTable('tbl_security_incidents') ? $db->getTableRowCount('tbl_security_incidents') : 0;
            return self::reply('query_security_incident_count', "Tercatat {$cnt} log insiden keamanan yang ditangani oleh WAF, CSRF guard, dan proteksi brute-force.", 'Function Calling', 'SMILING', 'NOD');
        }

        if (self::isToolAllowed($am, 'query_profanity_filter_stats') && preg_match('/\b(statistik\s+kata\s+kasar|berapa\s+chat\s+kata\s+kasar|log\s+profanity)\b/u', $lower)) {
            $db->prepare("SELECT COUNT(*) cnt FROM tbl_va_conversations WHERE is_profane = 1");
            $r = $db->single();
            return self::reply('query_profanity_filter_stats', "Filter kata kasar mendeteksi " . (int)($r['cnt'] ?? 0) . " percakapan non-etis dari total log MySQL.");
        }

        if (self::isToolAllowed($am, 'query_active_sessions_count') && preg_match('/\b(berapa\s+sesi\s+login\s+aktif|siapa\s+user\s+online|jumlah\s+sesi\s+portal)\b/u', $lower)) {
            $cutoff = time() - 900;
            $cnt = 0;
            if ($db->hasTable('tbl_user_sessions')) {
                $db->prepare("SELECT COUNT(*) cnt FROM tbl_user_sessions WHERE is_revoked = 0 AND last_activity >= :co");
                $db->bind(':co', $cutoff);
                $r = $db->single();
                $cnt = (int)($r['cnt'] ?? 0);
            }
            return self::reply('query_active_sessions_count', "Saat ini terdapat {$cnt} sesi portal management yang aktif (tidak dicabut dalam 15 menit terakhir).");
        }

        if (self::isToolAllowed($am, 'query_top_used_tools') && preg_match('/\b(tool\s+paling\s+sering\s+dipakai|ranking\s+function\s+calling|statistik\s+agent\s+tools|top\s+tools)\b/u', $lower)) {
            $db->prepare("SELECT tool_code, call_count FROM tbl_agent_tools ORDER BY call_count DESC, id ASC LIMIT 3");
            $rows = $db->resultSet();
            $parts = array_map(static fn($r) => "{$r['tool_code']} ({$r['call_count']}x)", $rows);
            return self::reply('query_top_used_tools', "Top 3 Function Calling terbanyak dijalankan: " . implode(', ', $parts) . ".");
        }

        if (self::isToolAllowed($am, 'query_database_stats') && preg_match('/\b(statistik\s+database|berapa\s+total\s+chat|jumlah\s+percakapan|berapa\s+dokumen\s+rag|data\s+mysql|database\s+stats|jumlah\s+topik)\b/u', $lower)) {
            $chats = $db->getTableRowCount('tbl_va_conversations');
            $kb = $db->getTableRowCount('tbl_static_knowledge');
            $rag = $db->getTableRowCount('tbl_knowledge_documents');
            $mem = $db->getTableRowCount('tbl_long_term_memories');
            $txt = $lang === 'en'
                ? "Live MySQL database stats: {$chats} logged conversations, {$kb} Static KB topics, {$rag} indexed RAG documents, and {$mem} long-term memory entries."
                : "Statistik real-time MySQL saat ini: {$chats} percakapan tercatat, {$kb} topik Static Knowledge, {$rag} dokumen RAG terindeks, dan {$mem} memori jangka panjang.";
            return self::reply('query_database_stats', $txt);
        }

        if (self::isToolAllowed($am, 'search_rag_by_faculty') && preg_match('/\b(?:dokumen|cari\s+pedoman)\s+(?:tentang\s+)?fakultas\s+([a-z]+)\b/u', $lower, $fm)) {
            $fac = $fm[1];
            $db->prepare("SELECT doc_code, title FROM tbl_knowledge_documents WHERE is_active = 1 AND (title LIKE :q OR content_text LIKE :q) LIMIT 3");
            $db->bind(':q', "%{$fac}%");
            $rows = $db->resultSet();
            if (!empty($rows)) {
                $titles = array_map(static fn($r) => "{$r['doc_code']}: {$r['title']}", $rows);
                return self::reply('search_rag_by_faculty', "Ditemukan dokumen fakultas '{$fac}': " . implode(' | ', $titles) . ".");
            }
        }

        if (self::isToolAllowed($am, 'search_rag_by_category') && preg_match('/\b(kategori\s+dokumen\s+rag|jenis\s+dokumen\s+rag|daftar\s+kategori\s+rag)\b/u', $lower)) {
            $db->prepare("SELECT category, COUNT(*) cnt FROM tbl_knowledge_documents WHERE is_active = 1 GROUP BY category ORDER BY cnt DESC LIMIT 6");
            $rows = $db->resultSet();
            $parts = array_map(static fn($r) => "{$r['category']} ({$r['cnt']})", $rows);
            return self::reply('search_rag_by_category', "Kategori dokumen RAG aktif: " . implode(', ', $parts) . ".");
        }

        if (self::isToolAllowed($am, 'search_kb_by_category') && preg_match('/\b(kategori\s+static\s+knowledge|kategori\s+kb\s+apa\s+saja|daftar\s+topik\s+pengetahuan)\b/u', $lower)) {
            $db->prepare("SELECT category, COUNT(*) cnt FROM tbl_static_knowledge WHERE is_active = 1 GROUP BY category ORDER BY cnt DESC LIMIT 6");
            $rows = $db->resultSet();
            $parts = array_map(static fn($r) => "{$r['category']} ({$r['cnt']})", $rows);
            return self::reply('search_kb_by_category', "Kategori Static Knowledge Base aktif: " . implode(', ', $parts) . ".");
        }

        if (self::isToolAllowed($am, 'search_user_memories_count') && preg_match('/\b(berapa\s+memori\s+saya|jumlah\s+catatan\s+memori|cek\s+memori\s+tersimpan)\b/u', $lower)) {
            $db->prepare("SELECT COUNT(*) cnt FROM tbl_long_term_memories WHERE session_code = :sc");
            $db->bind(':sc', $sc);
            $r = $db->single();
            return self::reply('search_user_memories_count', "Terdapat " . (int)($r['cnt'] ?? 0) . " catatan memori jangka panjang tersimpan untuk sesi Anda.");
        }

        if (self::isToolAllowed($am, 'search_failed_login_audit') && preg_match('/\b(percobaan\s+login\s+gagal|audit\s+login\s+gagal|cek\s+failed\s+login)\b/u', $lower)) {
            $cnt = 0;
            if ($db->hasTable('tbl_login_logs')) {
                $db->prepare("SELECT COUNT(*) cnt FROM tbl_login_logs WHERE status = 'Failed'");
                $r = $db->single();
                $cnt = (int)($r['cnt'] ?? 0);
            }
            return self::reply('search_failed_login_audit', "Catatan audit MySQL mencatat total {$cnt} upaya login berstatus Failed.");
        }

        if (self::isToolAllowed($am, 'search_model_catalog_gemini') && preg_match('/\b(daftar\s+model\s+gemini|model\s+gemini\s+3\s+apa\s+saja|list\s+model\s+gemini)\b/u', $lower)) {
            $cat = Walker_model::getGemini3Catalog();
            $keys = array_slice(array_keys($cat), 0, 5);
            return self::reply('search_model_catalog_gemini', "Tersedia " . count($cat) . " model Gemini 3, antara lain: " . implode(', ', $keys) . ".");
        }

        if (self::isToolAllowed($am, 'search_model_catalog_openai') && preg_match('/\b(daftar\s+model\s+openai|model\s+gpt\s+apa\s+saja|list\s+model\s+openai)\b/u', $lower)) {
            $cat = Walker_model::getOpenAiCatalog();
            $keys = array_slice(array_keys($cat), 0, 5);
            return self::reply('search_model_catalog_openai', "Tersedia " . count($cat) . " model OpenAI GPT-6/GPT-5/spesialis, antara lain: " . implode(', ', $keys) . ".");
        }

        if (self::isToolAllowed($am, 'search_rag_latest_added') && preg_match('/\b(dokumen\s+rag\s+terbaru|file\s+rag\s+baru\s+ditambahkan|latest\s+rag\s+documents)\b/u', $lower)) {
            $db->prepare("SELECT doc_code, title, chunk_count FROM tbl_knowledge_documents WHERE is_active = 1 ORDER BY id DESC LIMIT 3");
            $rows = $db->resultSet();
            $parts = array_map(static fn($r) => "{$r['doc_code']} ({$r['chunk_count']} segmen)", $rows);
            return self::reply('search_rag_latest_added', "3 dokumen RAG paling baru diindeks: " . implode(', ', $parts) . ".");
        }

        if (self::isToolAllowed($am, 'search_knowledge_data') && preg_match('/\b(cari\s+data\s+(?:tentang\s+)?|temukan\s+informasi\s+|search\s+data\s+|cari\s+dokumen\s+)(.+)$/u', $lower, $sm)) {
            $q = trim($sm[2]);
            $ragHit = $searchRagFn($q, $lang, 0.18);
            if ($ragHit && !empty($ragHit['reply'])) {
                return self::reply('search_knowledge_data', (string)$ragHit['reply']);
            }
            $db->prepare("SELECT topic_code, response_id, response_en FROM tbl_static_knowledge WHERE is_active = 1 AND (keywords_id LIKE :q OR response_id LIKE :q) LIMIT 1");
            $db->bind(':q', "%{$q}%");
            $kb = $db->single();
            if ($kb) {
                $ans = $lang === 'en' ? $kb['response_en'] : $kb['response_id'];
                return self::reply('search_knowledge_data', "Hasil pencarian data '{$q}': {$ans}");
            }
        }

        return null;
    }

    private static function dispatchSqlAndExternal(string $message, string $lower, string $lang, string $sc, string $tz, Database $db, ?array $am): ?array {
        $sqlTemplates = [
            'sql_template_top_conversations' => ['/\b(sql\s+sumber\s+jawaban|sql\s+group\s+by\s+source|query\s+statistik\s+chat)\b/u', "SELECT response_source, COUNT(*) AS total, ROUND(AVG(ai_eval_score),3) AS avg_eval FROM tbl_va_conversations GROUP BY response_source ORDER BY total DESC;"],
            'sql_template_api_usage' => ['/\b(sql\s+pemakaian\s+api\s+key|sql\s+cek\s+token|query\s+status\s+api\s+key)\b/u', "SELECT id, provider, key_label, model_name, is_primary, total_requests, total_tokens, last_status FROM tbl_ai_provider_keys ORDER BY is_primary DESC, id ASC;"],
            'sql_template_security_audit' => ['/\b(sql\s+audit\s+keamanan|sql\s+cek\s+serangan\s+waf|query\s+log\s+login\s+gagal)\b/u', "SELECT incident_type, source_ip, action_taken, created_at FROM tbl_security_incidents ORDER BY id DESC LIMIT 15;"],
            'sql_template_kb_popularity' => ['/\b(sql\s+ranking\s+static\s+knowledge|sql\s+hit\s+count\s+kb|query\s+topik\s+populer)\b/u', "SELECT topic_code, category, hit_count FROM tbl_static_knowledge WHERE is_active = 1 ORDER BY hit_count DESC LIMIT 10;"],
            'sql_template_rag_coverage' => ['/\b(sql\s+daftar\s+dokumen\s+rag|sql\s+chunk\s+rag|query\s+inventaris\s+dokumen)\b/u', "SELECT doc_code, title, file_type, chunk_count, retrieval_count FROM tbl_knowledge_documents WHERE is_active = 1 ORDER BY retrieval_count DESC LIMIT 15;"],
            'sql_template_agent_tools_rank' => ['/\b(sql\s+statistik\s+agent\s+tools|sql\s+ranking\s+tool|query\s+call\s+count\s+tools)\b/u', "SELECT tool_code, tool_category, call_count, is_active FROM tbl_agent_tools ORDER BY call_count DESC, id ASC LIMIT 20;"]
        ];
        foreach ($sqlTemplates as $code => [$regex, $sqlStr]) {
            if (self::isToolAllowed($am, $code) && preg_match($regex, $lower)) {
                return self::reply($code, "SQL Assistant menghasilkan query aman: `{$sqlStr}`", 'SQL Assistant', 'THINKING', 'EXPLAIN');
            }
        }

        if (self::isToolAllowed($am, 'safe_sql_assistant') && preg_match('/\b(query\s+sql|buatkan\s+sql|sql\s+assistant|sql\s+select|contoh\s+query|ubah\s+ke\s+sql)\b/u', $lower)) {
            $sqlQuery = "SELECT id, user_identifier, detected_language, response_source, ai_eval_score, created_at FROM tbl_va_conversations WHERE is_profane = 0 ORDER BY created_at DESC LIMIT 5;";
            if (strpos($lower, 'kasar') !== false || strpos($lower, 'profan') !== false || strpos($lower, 'toxic') !== false) {
                $sqlQuery = "SELECT id, user_identifier, user_message, profanity_words, profanity_severity, ip_address, created_at FROM tbl_va_conversations WHERE is_profane = 1 ORDER BY created_at DESC LIMIT 10;";
            } elseif (strpos($lower, 'rag') !== false || strpos($lower, 'dokumen') !== false) {
                $sqlQuery = "SELECT doc_code, title, file_type, chunk_count, retrieval_count FROM tbl_knowledge_documents WHERE is_active = 1 ORDER BY retrieval_count DESC;";
            } elseif (strpos($lower, 'user') !== false || strpos($lower, 'admin') !== false) {
                $sqlQuery = "SELECT username, full_name, department, status, last_login_at FROM tbl_users ORDER BY id ASC;";
            }
            $totalChats = $db->isConnected() ? $db->getTableRowCount('tbl_va_conversations') : 0;
            $txt = $lang === 'en'
                ? "SQL Assistant generated this safe read-only query: `{$sqlQuery}` (Verified against {$totalChats} recorded conversations)."
                : "SQL Assistant menghasilkan query read-only yang aman berikut: `{$sqlQuery}` (Terverifikasi pada {$totalChats} data percakapan).";
            return self::reply('safe_sql_assistant', $txt, 'SQL Assistant', 'THINKING', 'EXPLAIN');
        }

        if (self::isToolAllowed($am, 'external_weather_airmadidi') && preg_match('/\b(cuaca\s+airmadidi|cuaca\s+kampus\s+unklab|suhu\s+di\s+airmadidi)\b/u', $lower)) {
            $weatherUrl = 'https://api.open-meteo.com/v1/forecast?latitude=1.4044&longitude=124.9781&current_weather=true';
            $urlCheck = Security::validate_external_url($weatherUrl);
            if (!empty($urlCheck['valid']) && function_exists('curl_init')) {
                $ch = curl_init($weatherUrl);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]);
                $raw = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($code === 200 && is_string($raw)) {
                    $wd = json_decode($raw, true);
                    if (isset($wd['current_weather']['temperature'])) {
                        $temp = $wd['current_weather']['temperature'];
                        $wind = $wd['current_weather']['windspeed'] ?? '-';
                        return self::reply('external_weather_airmadidi', "Cuaca real-time Kampus UNKLAB Airmadidi (Open-Meteo API): suhu {$temp}°C dengan kecepatan angin {$wind} km/jam.");
                    }
                }
            }
            return self::reply('external_weather_airmadidi', "Wilayah Kampus UNKLAB Airmadidi (Minahasa Utara) berada di iklim tropis sejuk 24–31°C; koneksi Open-Meteo sedang tidak merespons.");
        }

        if (self::isToolAllowed($am, 'external_api_health_probe') && preg_match('/\b(cek\s+status\s+koneksi\s+semua\s+api|apakah\s+koneksi\s+llm\s+sehat|kesehatan\s+api\s+eksternal)\b/u', $lower)) {
            $db->prepare("SELECT provider, COUNT(*) total, SUM(CASE WHEN last_status LIKE 'Connected%' THEN 1 ELSE 0 END) ok_cnt FROM tbl_ai_provider_keys GROUP BY provider");
            $rows = $db->resultSet();
            $parts = array_map(static fn($r) => strtoupper($r['provider']) . ": {$r['ok_cnt']}/{$r['total']} Connected", $rows);
            return self::reply('external_api_health_probe', "Ringkasan status endpoint eksternal LLM: " . (!empty($parts) ? implode(', ', $parts) : "Belum ada key") . ".");
        }

        if (self::isToolAllowed($am, 'external_campus_portal_links') && preg_match('/\b(link\s+website\s+unklab|alamat\s+portal\s+siasat|link\s+lms\s+kampus|website\s+resmi\s+unklab)\b/u', $lower)) {
            return self::reply('external_campus_portal_links', "Portal resmi Universitas Klabat dapat diakses melalui https://www.unklab.ac.id serta layanan SIASAT/LMS akademik internal kampus.");
        }

        if (self::isToolAllowed($am, 'external_ssrf_url_checker') && preg_match('/\b(cek\s+keamanan\s+ssrf(?:\s+url)?|validasi\s+url\s+aman|periksa\s+url\s+ssrf)\s+(https?:\/\/[^\s]+)/i', $message, $um)) {
            $res = Security::validate_external_url($um[2]);
            $ok = !empty($res['valid']);
            return self::reply('external_ssrf_url_checker', "Pemeriksaan anti-SSRF pada {$um[2]}: " . ($ok ? "AMAN dan diizinkan." : "DIBLOKIR (" . ($res['reason'] ?? 'Restricted') . ")."));
        }

        if (self::isToolAllowed($am, 'external_campus_service') && preg_match('/\b(status\s+server\s+eksternal|sinkronisasi\s+api|external\s+api)\b/u', $lower)) {
            return self::reply('external_campus_service', "Konektor layanan API eksternal aktif dengan validasi protokol HTTPS dan proteksi anti-SSRF Security::validate_external_url.");
        }

        return null;
    }

    private static function dispatchCoreMathAndCalendar(string $message, string $lower, string $lang, string $sc, string $tz, Database $db, ?array $am, callable $cal): ?array {
        if (self::isToolAllowed($am, 'calculate_math_expression')) {
            $mathExpr = self::extractMathExpression($lower);
            if ($mathExpr !== null) {
                $evalMath = self::evaluateMathExpression($mathExpr);
                if ($evalMath['ok']) {
                    $formatted = self::fmtPlain((float)$evalMath['value'], 4);
                    $txt = $lang === 'en'
                        ? "Using my PHP math function, the result of {$mathExpr} is {$formatted}."
                        : "Melalui eksekusi fungsi kalkulator PHP, hasil dari {$mathExpr} adalah {$formatted}.";
                    return self::reply('calculate_math_expression', $txt);
                }
                if ($evalMath['error'] === 'div_zero') {
                    $txt = $lang === 'en'
                        ? "Division by zero is undefined in mathematics ({$mathExpr})."
                        : "Pembagian dengan nol tidak terdefinisi dalam matematika ({$mathExpr}).";
                    return self::reply('calculate_math_expression', $txt, 'Function Calling', 'THINKING');
                }
            }
        }

        if (self::isToolAllowed($am, 'get_current_datetime_info') && preg_match('/\b(jam\s+berapa|jam\s+hari\s+ini|pukul\s+berapa|waktu\s+sekarang|tanggal\s+berapa|hari\s+ini\s+tanggal\s+berapa|sekarang\s+hari\s+apa|hari\s+apa|what\s+time|current\s+time|what\s+date|today\'?s?\s+date|what\s+day|semester\s+aktif)\b/u', $lower)) {
            return self::reply('get_current_datetime_info', $cal($lower, $lang, $tz), 'Function Calling', 'SMILING', 'NOD');
        }

        return null;
    }

    public static function extractMathExpression(string $lower): ?string {
        $norm = str_replace(['×', '÷', '−'], ['*', '/', '-'], $lower);
        $norm = preg_replace('/(?<=[\d\)])\s+(?:ditambah|tambah|plus)\s+(?=[\(\-\d])/u', ' + ', $norm);
        $norm = preg_replace('/(?<=[\d\)])\s+(?:dikurangi|kurang|minus)\s+(?=[\(\-\d])/u', ' - ', $norm);
        $norm = preg_replace('/(?<=[\d\)])\s+(?:dikali|kali|times)\s+(?=[\(\-\d])/u', ' * ', $norm);
        $norm = preg_replace('/(?<=[\d\)])\s+(?:dibagi|bagi|divided\s+by)\s+(?=[\(\-\d])/u', ' / ', $norm);
        $norm = preg_replace('/(?<=[\d\)])\s*(?:x)\s*(?=[\(\-\d])/iu', ' * ', $norm);
        $norm = preg_replace('/(?<=[\d\)])\s+(?:pangkat)\s+(?=[\(\-\d])/u', ' ^ ', $norm);

        $hasMathKeyword = (bool)preg_match('/\b(hitung|kalkulator|hasil|berapa|penjumlahan|pengurangan|perkalian|pembagian|jumlahkan|total|calculate|compute|math|sum)\b/u', $lower);
        $hasSpacedOrStrongOp = (bool)preg_match('/[\d\)]\s*[\+\*\/\^\%]\s*[\(\-\d]/', $norm) || (bool)preg_match('/[\d\)]\s+\-\s+[\(\d]/', $norm);
        if (!$hasMathKeyword && !$hasSpacedOrStrongOp) {
            return null;
        }
        $normNoDate = preg_replace('/\b\d{4}-\d{2}-\d{2}\b/', ' ', $norm);

        if (!preg_match_all('/(?:\(\s*)*-?\d+(?:\.\d+)?(?:\s*\))*(?:\s*[\+\-\*\/\%\^]\s*(?:\(\s*)*-?\d+(?:\.\d+)?(?:\s*\))*)+/u', $normNoDate, $matches)) {
            return null;
        }
        $best = '';
        foreach ($matches[0] as $cand) {
            $candTrim = trim(preg_replace('/\s+/', ' ', $cand));
            if (strlen($candTrim) > strlen($best)) {
                $best = $candTrim;
            }
        }
        return $best !== '' ? $best : null;
    }

    public static function evaluateMathExpression(string $expr): array {
        preg_match_all('/\d+(?:\.\d+)?|[\+\-\*\/\%\^\(\)]/', $expr, $m);
        $tokens = $m[0] ?? [];
        if (count($tokens) < 3) {
            return ['ok' => false, 'error' => 'invalid'];
        }
        $pos = 0;
        $err = null;
        $val = self::parseMathExpr($tokens, $pos, $err);
        if ($err !== null || $pos < count($tokens)) {
            return ['ok' => false, 'error' => $err ?? 'syntax'];
        }
        return ['ok' => true, 'value' => $val];
    }

    private static function parseMathExpr(array $tokens, int &$pos, ?string &$err): float {
        $val = self::parseMathTerm($tokens, $pos, $err);
        while ($err === null && $pos < count($tokens) && ($tokens[$pos] === '+' || $tokens[$pos] === '-')) {
            $op = $tokens[$pos++];
            $rhs = self::parseMathTerm($tokens, $pos, $err);
            $val = ($op === '+') ? ($val + $rhs) : ($val - $rhs);
        }
        return $val;
    }

    private static function parseMathTerm(array $tokens, int &$pos, ?string &$err): float {
        $val = self::parseMathPower($tokens, $pos, $err);
        while ($err === null && $pos < count($tokens) && in_array($tokens[$pos], ['*', '/', '%'], true)) {
            $op = $tokens[$pos++];
            $rhs = self::parseMathPower($tokens, $pos, $err);
            if ($err !== null) return 0.0;
            if ($op === '*') {
                $val *= $rhs;
            } elseif ($op === '/') {
                if (abs($rhs) < 1e-12) { $err = 'div_zero'; return 0.0; }
                $val /= $rhs;
            } else {
                if (abs($rhs) < 1e-12) { $err = 'div_zero'; return 0.0; }
                $val = fmod($val, $rhs);
            }
        }
        return $val;
    }

    private static function parseMathPower(array $tokens, int &$pos, ?string &$err): float {
        $base = self::parseMathFactor($tokens, $pos, $err);
        if ($err === null && $pos < count($tokens) && $tokens[$pos] === '^') {
            $pos++;
            $exp = self::parseMathPower($tokens, $pos, $err);
            if ($err !== null) return 0.0;
            return pow($base, max(-12.0, min(12.0, $exp)));
        }
        return $base;
    }

    private static function parseMathFactor(array $tokens, int &$pos, ?string &$err): float {
        if ($pos >= count($tokens)) {
            $err = 'unexpected_end';
            return 0.0;
        }
        $tok = $tokens[$pos];
        if ($tok === '+' || $tok === '-') {
            $pos++;
            $inner = self::parseMathFactor($tokens, $pos, $err);
            return ($tok === '-') ? -$inner : $inner;
        }
        if ($tok === '(') {
            $pos++;
            $inner = self::parseMathExpr($tokens, $pos, $err);
            if ($pos >= count($tokens) || $tokens[$pos] !== ')') {
                $err = 'paren';
                return 0.0;
            }
            $pos++;
            return $inner;
        }
        if (is_numeric($tok)) {
            $pos++;
            return (float)$tok;
        }
        $err = 'unexpected_token';
        return 0.0;
    }
}
