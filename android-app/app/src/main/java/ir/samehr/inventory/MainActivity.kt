package ir.samehr.inventory

import android.os.Bundle
import android.view.View
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.recyclerview.widget.LinearLayoutManager
import ir.samehr.inventory.data.*
import ir.samehr.inventory.databinding.ActivityMainBinding
import ir.samehr.inventory.ui.ProductAdapter
import retrofit2.Call
import retrofit2.Callback
import retrofit2.Response

class MainActivity : AppCompatActivity() {
    private lateinit var b: ActivityMainBinding
    private lateinit var api: InventoryApi
    private val adapter=ProductAdapter(::confirmChange)
    override fun onCreate(state:Bundle?){ super.onCreate(state); b=ActivityMainBinding.inflate(layoutInflater); setContentView(b.root); b.products.layoutManager=LinearLayoutManager(this); b.products.adapter=adapter; val p=getSharedPreferences("auth",MODE_PRIVATE); b.siteUrl.setText(p.getString("site","https://samehrstore.ir")); b.username.setText(p.getString("user","")); b.loginButton.setOnClickListener{login()}; b.searchButton.setOnClickListener{load(false)}; b.lowButton.setOnClickListener{load(true)}; b.logoutButton.setOnClickListener{p.edit().clear().apply(); b.contentPanel.visibility=View.GONE; b.loginPanel.visibility=View.VISIBLE} }
    private fun login(){ val site=b.siteUrl.text.toString(); val user=b.username.text.toString(); val pass=b.appPassword.text.toString(); if(site.isBlank()||user.isBlank()||pass.isBlank()){toast("همه فیلدها را تکمیل کنید");return}; api=ApiFactory.create(site,user,pass); getSharedPreferences("auth",MODE_PRIVATE).edit().putString("site",site).putString("user",user).apply(); b.loginPanel.visibility=View.GONE; b.contentPanel.visibility=View.VISIBLE; load(false); loadSummary() }
    private fun load(low:Boolean){ b.progress.visibility=View.VISIBLE; api.products(b.search.text.toString(),low).enqueue(object:Callback<ProductsResponse>{override fun onResponse(c:Call<ProductsResponse>,r:Response<ProductsResponse>){b.progress.visibility=View.GONE;if(r.isSuccessful)adapter.submit(r.body()?.items.orEmpty())else authError(r.code())};override fun onFailure(c:Call<ProductsResponse>,t:Throwable){b.progress.visibility=View.GONE;toast("خطا در اتصال: ${t.localizedMessage}")}}) }
    private fun loadSummary(){api.summary().enqueue(object:Callback<Summary>{override fun onResponse(c:Call<Summary>,r:Response<Summary>){r.body()?.let{b.summary.text="محصولات: ${it.products}  |  کم‌موجود: ${it.low_stock}  |  ناموجود: ${it.out_of_stock}"}};override fun onFailure(c:Call<Summary>,t:Throwable){}})}
    private fun confirmChange(p:Product,mode:String){val label=if(mode=="increase")"افزایش" else "کاهش";AlertDialog.Builder(this).setTitle("$label موجودی").setMessage("موجودی «${p.name}» یک عدد $label یابد؟").setNegativeButton("انصراف",null).setPositiveButton("تأیید"){_,_->update(p,mode)}.show()}
    private fun update(p:Product,mode:String){api.stock(p.id,StockRequest(mode,1)).enqueue(object:Callback<StockResponse>{override fun onResponse(c:Call<StockResponse>,r:Response<StockResponse>){if(r.isSuccessful){r.body()?.let{adapter.update(it.product_id,it.stock_after)};loadSummary()}else authError(r.code())};override fun onFailure(c:Call<StockResponse>,t:Throwable){toast("تغییر موجودی انجام نشد")}})}
    private fun authError(code:Int){toast(if(code==401||code==403)"نام کاربری، رمز برنامه یا دسترسی کاربر درست نیست" else "خطای سرور: $code")}
    private fun toast(s:String)=Toast.makeText(this,s,Toast.LENGTH_LONG).show()
}
