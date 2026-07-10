package ir.samehr.inventory.data

import retrofit2.Call
import retrofit2.http.*

interface InventoryApi {
    @GET("wp-json/samehr-inventory/v1/products") fun products(@Query("search") search: String = "", @Query("low_stock") low: Boolean = false): Call<ProductsResponse>
    @GET("wp-json/samehr-inventory/v1/summary") fun summary(): Call<Summary>
    @POST("wp-json/samehr-inventory/v1/products/{id}/stock") fun stock(@Path("id") id: Long, @Body body: StockRequest): Call<StockResponse>
}
