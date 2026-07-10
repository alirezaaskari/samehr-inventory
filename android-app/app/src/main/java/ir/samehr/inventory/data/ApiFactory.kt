package ir.samehr.inventory.data

import okhttp3.Credentials
import okhttp3.OkHttpClient
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory

object ApiFactory {
    fun create(site: String, user: String, password: String): InventoryApi {
        val base = site.trim().trimEnd('/') + "/"
        val client = OkHttpClient.Builder().addInterceptor { chain ->
            chain.proceed(chain.request().newBuilder().header("Authorization", Credentials.basic(user, password)).header("Accept", "application/json").build())
        }.build()
        return Retrofit.Builder().baseUrl(base).client(client).addConverterFactory(GsonConverterFactory.create()).build().create(InventoryApi::class.java)
    }
}
